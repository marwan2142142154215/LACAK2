import { test } from '@japa/runner'
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client'
import crypto from 'node:crypto'
import bcrypt from 'bcryptjs'
import db from '@adonisjs/lucid/services/db'
import server from '@adonisjs/core/services/server'
import redis from '@adonisjs/redis/services/main'

/**
 * Test ini membuat row site/team/device/device_credentials LANGSUNG (bukan lewat
 * endpoint registration Laravel, yang baru dibangun di PHASE 9) — skema tabelnya
 * sudah ada dari migration Laravel (PHASE 2), jadi valid untuk dipakai di sini.
 */
async function seedDevice(rawSecret: string) {
  const siteId = crypto.randomUUID()
  const teamId = crypto.randomUUID()
  const deviceId = crypto.randomUUID()
  const publicTokenId = crypto.randomUUID()

  await db.table('sites').insert({
    id: siteId,
    name: 'WS Test Site',
    code: `ws-site-${Date.now()}`,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })

  await db.table('teams').insert({
    id: teamId,
    site_id: siteId,
    name: 'WS Test Team',
    code: `ws-team-${Date.now()}`,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })

  await db.table('devices').insert({
    id: deviceId,
    site_id: siteId,
    team_id: teamId,
    name: 'WS Test Device',
    status: 'UNKNOWN',
    is_managed: false,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })

  const credentialHash = await bcrypt.hash(rawSecret, 10)

  await db.table('device_credentials').insert({
    id: crypto.randomUUID(),
    device_id: deviceId,
    credential_hash: credentialHash,
    public_token_id: publicTokenId,
    issued_at: new Date(),
    created_at: new Date(),
    updated_at: new Date(),
  })

  return { deviceId, publicTokenId, siteId, teamId }
}

function wsUrl(): string {
  const httpServer = server.getNodeServer()
  const address = httpServer!.address()
  const port = typeof address === 'object' && address ? address.port : 0
  return `http://127.0.0.1:${port}`
}

function connectClient(url: string, auth: Record<string, string>): Promise<ClientSocket> {
  return new Promise((resolve, reject) => {
    const socket = ioClient(url, {
      path: '/ws',
      auth,
      reconnection: false,
      timeout: 3000,
      // §43/§44: WAJIB wss:// murni, bukan HTTP long-polling fallback (juga menghindari
      // polling request Engine.IO bentrok dengan router AdonisJS di http.Server yang sama).
      transports: ['websocket'],
    })
    socket.on('connect', () => resolve(socket))
    socket.on('connect_error', (err) => reject(err))
    socket.on('auth.error', (payload) => reject(new Error(payload.message)))
  })
}

test.group('WebSocket device gateway', (group) => {
  let createdDeviceIds: string[] = []

  group.each.teardown(async () => {
    for (const id of createdDeviceIds) {
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('device_sessions').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    createdDeviceIds = []
  })

  test('authenticates a device with valid credential and joins its room', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('correct-secret')
    createdDeviceIds.push(deviceId)

    const socket = await connectClient(wsUrl(), {
      device_id: deviceId,
      public_token_id: publicTokenId,
      device_secret: 'correct-secret',
    })

    const connected = await new Promise((resolve) => socket.on('device.connected', resolve))
    assert.equal((connected as any).device_id, deviceId)

    const presence = await redis.get(`device:presence:${deviceId}`)
    assert.equal(presence, '1')

    socket.disconnect()
  })

  test('rejects a device with wrong secret', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('correct-secret')
    createdDeviceIds.push(deviceId)

    await assert.rejects(() =>
      connectClient(wsUrl(), {
        device_id: deviceId,
        public_token_id: publicTokenId,
        device_secret: 'WRONG-secret',
      })
    )
  })

  test('rejects a device with unknown public_token_id', async ({ assert }) => {
    const { deviceId } = await seedDevice('correct-secret')
    createdDeviceIds.push(deviceId)

    await assert.rejects(() =>
      connectClient(wsUrl(), {
        device_id: deviceId,
        public_token_id: crypto.randomUUID(),
        device_secret: 'correct-secret',
      })
    )
  })

  test('rejects connection missing device_secret entirely (§43 — not just device_id)', async ({
    assert,
  }) => {
    const { deviceId, publicTokenId } = await seedDevice('correct-secret')
    createdDeviceIds.push(deviceId)

    await assert.rejects(() =>
      connectClient(wsUrl(), { device_id: deviceId, public_token_id: publicTokenId } as any)
    )
  })

  test('records a device_sessions row and clears it + presence on disconnect', async ({
    assert,
  }) => {
    const { deviceId, publicTokenId } = await seedDevice('correct-secret')
    createdDeviceIds.push(deviceId)

    const socket = await connectClient(wsUrl(), {
      device_id: deviceId,
      public_token_id: publicTokenId,
      device_secret: 'correct-secret',
    })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const session = await db.from('device_sessions').where('device_id', deviceId).first()
    assert.isNotNull(session)
    assert.isNull(session.disconnected_at)

    socket.disconnect()
    await new Promise((resolve) => setTimeout(resolve, 300))

    const updated = await db.from('device_sessions').where('device_id', deviceId).first()
    assert.isNotNull(updated.disconnected_at)

    const presence = await redis.get(`device:presence:${deviceId}`)
    assert.isNull(presence)
  })

  test('rejects a device credential that has been revoked', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('correct-secret')
    createdDeviceIds.push(deviceId)

    await db
      .from('device_credentials')
      .where('device_id', deviceId)
      .update({ revoked_at: new Date() })

    await assert.rejects(() =>
      connectClient(wsUrl(), {
        device_id: deviceId,
        public_token_id: publicTokenId,
        device_secret: 'correct-secret',
      })
    )
  })
})
