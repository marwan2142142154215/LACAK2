import type { Server as HttpServer } from 'node:http'
import crypto from 'node:crypto'
import { Server as SocketIOServer, type Socket } from 'socket.io'
import bcrypt from 'bcryptjs'
import { DateTime } from 'luxon'
import logger from '@adonisjs/core/services/logger'
import redis from '@adonisjs/redis/services/main'
import Device from '#models/device'
import DeviceCredential from '#models/device_credential'
import DeviceSession from '#models/device_session'

/**
 * §43/§44 — Device Gateway WebSocket.
 *
 * Event yang didokumentasikan (lihat docs/websocket.md untuk daftar lengkap):
 *   - device.connected     : device berhasil authenticated & join room
 *   - device.disconnected  : device putus koneksi
 *
 * Auth handshake WAJIB membawa device_id + public_token_id + device_secret (§43 —
 * "Jangan menerima device_id saja sebagai authentication"). device_secret diverifikasi
 * terhadap credential_hash (bcrypt) yang diterbitkan Laravel saat registration (PHASE 9).
 *
 * PENTING: autentikasi dilakukan di `io.use()` middleware, BUKAN di dalam handler
 * `io.on('connection', ...)`. Begitu handler 'connection' terpanggil, client SUDAH
 * menerima event 'connect' di level transport — socket.disconnect() setelah titik itu
 * datang terlambat (client sudah menganggap diri "tersambung" sesaat sebelum diputus,
 * race condition nyata, bukan cuma teori — ditemukan lewat test end-to-end PHASE 8).
 * Middleware `next(new Error(...))` menolak handshake SEBELUM 'connect' terkirim, yang
 * membuat client menerima 'connect_error', bukan 'connect' lalu 'disconnect'.
 */

interface DeviceAuthPayload {
  device_id?: string
  public_token_id?: string
  device_secret?: string
}

interface SocketData {
  deviceId: string
}

/** Map socket.id -> konteks device untuk kebutuhan disconnect handler & command targeting */
interface ConnectedDeviceContext {
  deviceId: string
  deviceSessionId: string
}

class WebSocketService {
  #io: SocketIOServer | null = null
  #connections = new Map<string, ConnectedDeviceContext>()

  boot(httpServer: HttpServer) {
    if (this.#io) return this.#io

    this.#io = new SocketIOServer(httpServer, {
      path: '/ws',
      cors: { origin: '*' }, // diperketat ke domain resmi saat PHASE 20 (Cloudflare)
      // HANYA websocket murni (§43/§44) — bukan HTTP long-polling. Fallback "HTTPS" di §45
      // adalah endpoint REST kita sendiri (command sync polling, PHASE 11/12), bukan
      // transport internal Socket.IO.
      transports: ['websocket'],
    })

    this.#io.use((socket, next) => this.#authenticateMiddleware(socket, next))
    this.#io.on('connection', (socket) => this.#handleConnection(socket))

    logger.info('WebSocket gateway attached at /ws')

    return this.#io
  }

  get io(): SocketIOServer {
    if (!this.#io) throw new Error('WebSocketService.boot() belum dipanggil')
    return this.#io
  }

  /** Jumlah device yang sedang terhubung — dipakai health check & dashboard (§48). */
  get connectedCount(): number {
    return this.#connections.size
  }

  async #authenticateMiddleware(socket: Socket, next: (err?: Error) => void) {
    try {
      const auth = socket.handshake.auth as DeviceAuthPayload
      const { device_id: deviceId, public_token_id: publicTokenId, device_secret: secret } = auth

      if (!deviceId || !publicTokenId || !secret) {
        return next(new Error('device_id, public_token_id, dan device_secret wajib diisi.'))
      }

      const credential = await DeviceCredential.query()
        .where('device_id', deviceId)
        .where('public_token_id', publicTokenId)
        .whereNull('revoked_at')
        .first()

      if (!credential) {
        logger.warn({ deviceId }, 'device.auth.rejected: no active credential')
        return next(new Error('Kredensial device tidak ditemukan atau sudah dicabut.'))
      }

      const valid = await bcrypt.compare(secret, credential.credentialHash)
      if (!valid) {
        logger.warn({ deviceId }, 'device.auth.rejected: secret mismatch')
        return next(new Error('Device secret tidak valid.'))
      }

      const device = await Device.query().where('id', deviceId).where('is_active', true).first()
      if (!device) {
        logger.warn({ deviceId }, 'device.auth.rejected: device inactive or not found')
        return next(new Error('Device tidak aktif atau tidak ditemukan.'))
      }

      ;(socket.data as SocketData).deviceId = deviceId
      next()
    } catch (error) {
      logger.error({ err: error }, 'device.auth.error')
      next(new Error('Terjadi kesalahan internal saat autentikasi.'))
    }
  }

  async #handleConnection(socket: Socket) {
    const { deviceId } = socket.data as SocketData

    try {
      const deviceSession = await this.#createSession(deviceId)
      this.#connections.set(socket.id, { deviceId, deviceSessionId: deviceSession.id })

      socket.join(`device:${deviceId}`)
      await this.#setPresence(deviceId)

      logger.info({ deviceId }, 'device.connected')
      socket.emit('device.connected', {
        device_id: deviceId,
        connected_at: DateTime.now().toISO(),
      })

      socket.on('disconnect', (reason) => this.#handleDisconnect(socket, reason))
    } catch (error) {
      // §70: kalau ada kegagalan SETELAH handshake diterima (misal DB down saat create
      // session), tetap beri sinyal jelas ke client lalu putus — jangan dibiarkan "connected"
      // secara diam-diam padahal server gagal mencatatnya.
      logger.error({ err: error, deviceId }, 'device.connection.error')
      socket.emit('connection.error', { message: 'Terjadi kesalahan internal setelah autentikasi.' })
      socket.disconnect(true)
    }
  }

  async #createSession(deviceId: string): Promise<DeviceSession> {
    const sessionToken = crypto.randomBytes(32).toString('hex')
    const sessionTokenHash = crypto.createHash('sha256').update(sessionToken).digest('hex')

    const session = new DeviceSession()
    session.id = crypto.randomUUID()
    session.deviceId = deviceId
    session.sessionTokenHash = sessionTokenHash
    session.connectionType = 'WEBSOCKET'
    session.connectedAt = DateTime.now()
    session.lastActivityAt = DateTime.now()
    await session.save()

    return session
  }

  async #setPresence(deviceId: string) {
    // TTL = heartbeat timeout (§6) — dikonfigurasi ulang di PHASE 11 saat heartbeat nyata
    // berjalan. Untuk sekarang, presence di-refresh tiap connect; expiry murni safety-net.
    await redis.set(`device:presence:${deviceId}`, '1', 'EX', 120)
  }

  async #handleDisconnect(socket: Socket, reason: string) {
    const ctx = this.#connections.get(socket.id)
    this.#connections.delete(socket.id)
    if (!ctx) return

    await DeviceSession.query().where('id', ctx.deviceSessionId).update({
      disconnected_at: DateTime.now().toSQL(),
      disconnect_reason: reason,
    })

    await redis.del(`device:presence:${ctx.deviceId}`)

    logger.info({ deviceId: ctx.deviceId, reason }, 'device.disconnected')
    this.#io
      ?.to(`device:${ctx.deviceId}`)
      .emit('device.disconnected', { device_id: ctx.deviceId, reason })
  }
}

export default new WebSocketService()
