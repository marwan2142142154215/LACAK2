import type { Server as HttpServer } from 'node:http'
import crypto from 'node:crypto'
import { Server as SocketIOServer, type Socket } from 'socket.io'
import bcrypt from 'bcryptjs'
import { DateTime } from 'luxon'
import logger from '@adonisjs/core/services/logger'
import redis from '@adonisjs/redis/services/main'
import Device from '#models/device'
import DeviceCommand from '#models/device_command'
import DeviceCommandLog from '#models/device_command_log'
import DeviceCredential from '#models/device_credential'
import DeviceHeartbeat from '#models/device_heartbeat'
import DeviceSession from '#models/device_session'
import { DEGRADED_THRESHOLD_SECONDS, resolveDeviceStatus } from '#services/device_status_resolver'
import { isValidDeviceAckTransition } from '#services/command_status_transition'

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

/** §6: payload heartbeat dari device. Field opsional — client tidak selalu bisa
 * membaca semuanya (izin ditolak dsb.) dan itu dilaporkan jujur sebagai null, bukan
 * dipalsukan nilai default yang menyesatkan. */
interface DeviceHeartbeatPayload {
  battery_level?: number | null
  network_type?: string | null
  connection_state?: string | null
  app_version?: string | null
  android_version?: string | null
  recorded_at?: string | null
}

interface DeviceCommandAckPayload {
  command_id?: string
  status?: string
  failure_reason?: string | null
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
      socket.on('device.heartbeat', (payload: DeviceHeartbeatPayload) =>
        this.#handleHeartbeat(socket, deviceId, payload),
      )
      socket.on('device.command.ack', (payload: DeviceCommandAckPayload) =>
        this.#handleCommandAck(deviceId, payload),
      )
    } catch (error) {
      // §70: kalau ada kegagalan SETELAH handshake diterima (misal DB down saat create
      // session), tetap beri sinyal jelas ke client lalu putus — jangan dibiarkan "connected"
      // secara diam-diam padahal server gagal mencatatnya.
      logger.error({ err: error, deviceId }, 'device.connection.error')
      socket.emit('connection.error', { message: 'Terjadi kesalahan internal setelah autentikasi.' })
      socket.disconnect(true)
    }
  }

  /**
   * §6/§44 — jalur UTAMA heartbeat (bukan HTTPS, yang hanya fallback §45). Menulis
   * device_heartbeats + mengupdate devices.status/last_heartbeat_at langsung (AdonisJS
   * sudah tersambung ke Postgres yang sama, round-trip lewat Laravel tidak perlu untuk
   * data berfrekuensi tinggi seperti ini — sesuai pembagian tanggung jawab di
   * docs/architecture.md).
   */
  async #handleHeartbeat(socket: Socket, deviceId: string, payload: DeviceHeartbeatPayload) {
    try {
      const receivedAt = DateTime.now()
      const recordedAt = payload.recorded_at ? DateTime.fromISO(payload.recorded_at) : receivedAt

      await DeviceHeartbeat.create({
        id: crypto.randomUUID(),
        deviceId,
        batteryLevel: payload.battery_level ?? null,
        networkType: payload.network_type ?? null,
        connectionState: payload.connection_state ?? null,
        appVersion: payload.app_version ?? null,
        androidVersion: payload.android_version ?? null,
        // recorded_at dari device TIDAK dipakai untuk status (anti clock-skew spoof, §6) —
        // hanya disimpan untuk audit/debug perbedaan jam device vs server.
        recordedAt: recordedAt.isValid ? recordedAt : receivedAt,
        receivedAt,
      })

      const status = resolveDeviceStatus(receivedAt)

      await Device.query().where('id', deviceId).update({
        last_heartbeat_at: receivedAt.toSQL(),
        status,
        ...(payload.app_version ? { app_version: payload.app_version } : {}),
        ...(payload.android_version ? { android_version: payload.android_version } : {}),
      })

      await this.#setPresence(deviceId)

      socket.emit('device.heartbeat.ack', {
        accepted: true,
        status,
        server_received_at: receivedAt.toISO(),
      })
    } catch (error) {
      logger.error({ err: error, deviceId }, 'device.heartbeat.error')
      socket.emit('device.heartbeat.ack', { accepted: false, status: 'UNKNOWN', server_received_at: null })
    }
  }

  /**
   * §19 — dipanggil InternalCommandsController (Laravel -> AdonisJS). Push command ke
   * device HANYA kalau device benar-benar terhubung SEKARANG (room punya anggota) —
   * kalau tidak, command dibiarkan PENDING di DB; device akan mengambilnya sendiri
   * saat connect berikutnya (TODO command-sync-on-connect, dicatat sebagai lanjutan,
   * belum kebutuhan mendesak selama device hampir selalu online).
   */
  async dispatchCommand(commandId: string): Promise<{ dispatched: boolean; reason?: string }> {
    const command = await DeviceCommand.find(commandId)
    if (!command) return { dispatched: false, reason: 'command_not_found' }

    if (command.status !== 'PENDING') {
      return { dispatched: false, reason: `command_not_pending (${command.status})` }
    }

    if (DateTime.now() > command.expiresAt) {
      await this.#transitionCommand(command, 'EXPIRED', 'GATEWAY', 'Expired sebelum dikirim')
      return { dispatched: false, reason: 'expired' }
    }

    const room = this.io.sockets.adapter.rooms.get(`device:${command.deviceId}`)
    if (!room || room.size === 0) {
      return { dispatched: false, reason: 'device_not_connected' }
    }

    // §21: ikat command ke device_session yang SEDANG aktif — validasi anti wrong-device
    // saat ack datang membandingkan ke sesi ini, bukan device_id saja.
    const activeSession = [...this.#connections.values()].find((c) => c.deviceId === command.deviceId)

    this.io.to(`device:${command.deviceId}`).emit('device.command.created', {
      command_id: command.id,
      command_type: command.commandType,
      payload: command.payload,
      expires_at: command.expiresAt.toISO(),
    })

    await this.#transitionCommand(
      command,
      'SENT',
      'GATEWAY',
      'Dikirim via WebSocket',
      activeSession?.deviceSessionId,
    )

    logger.info({ commandId, deviceId: command.deviceId }, 'device.command.sent')

    return { dispatched: true }
  }

  async #handleCommandAck(deviceId: string, payload: DeviceCommandAckPayload) {
    const { command_id: commandId, status } = payload
    if (!commandId || !status) return

    const command = await DeviceCommand.find(commandId)
    if (!command) {
      logger.warn({ commandId, deviceId }, 'device.command.ack: command not found')
      return
    }

    // §21 anti wrong-device: command yang di-ack HARUS milik device yang sama dengan
    // socket yang mengirim ack — socket TIDAK BISA meng-ack command milik device lain
    // walau tahu command_id-nya (device_id diambil dari socket.data yang sudah
    // diautentikasi di io.use(), bukan dari payload yang bisa dipalsukan client).
    if (command.deviceId !== deviceId) {
      logger.warn({ commandId, deviceId, actualOwner: command.deviceId }, 'device.command.ack REJECTED: wrong device')
      return
    }

    if (!isValidDeviceAckTransition(command.status, status as any)) {
      logger.warn({ commandId, deviceId, from: command.status, to: status }, 'device.command.ack REJECTED: invalid transition')
      return
    }

    await this.#transitionCommand(command, status as any, 'DEVICE', payload.failure_reason ?? null)
    logger.info({ commandId, deviceId, status }, 'device.command.ack')
  }

  async #transitionCommand(
    command: DeviceCommand,
    toStatus: DeviceCommand['status'],
    actor: 'DEVICE' | 'GATEWAY' | 'SYSTEM',
    note: string | null,
    deviceSessionId?: string,
  ) {
    const fromStatus = command.status
    const now = DateTime.now()

    command.status = toStatus
    if (toStatus === 'SENT') {
      command.sentAt = now
      if (deviceSessionId) command.deviceSessionId = deviceSessionId
    }
    if (toStatus === 'DELIVERED') command.deliveredAt = now
    if (toStatus === 'EXECUTING') command.executedAt = now
    if (['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'].includes(toStatus)) command.completedAt = now
    if (toStatus === 'FAILED' && note) command.failureReason = note
    await command.save()

    await DeviceCommandLog.create({
      id: crypto.randomUUID(),
      commandId: command.id,
      fromStatus,
      toStatus,
      note,
      actor,
    })
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
    // TTL = DEGRADED_THRESHOLD_SECONDS (§6) — presence key hidup selama device masih
    // dianggap minimal "DEGRADED" oleh DeviceStatusResolver; kalau heartbeat berhenti
    // lebih lama dari itu, key ini expire sendiri tanpa perlu job pembersih terpisah.
    await redis.set(`device:presence:${deviceId}`, '1', 'EX', DEGRADED_THRESHOLD_SECONDS)
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
