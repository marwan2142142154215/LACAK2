import { test } from '@japa/runner'
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client'
import crypto from 'node:crypto'
import bcrypt from 'bcryptjs'
import db from '@adonisjs/lucid/services/db'
import server from '@adonisjs/core/services/server'

async function seedDevice(rawSecret: string) {
  const siteId = crypto.randomUUID()
  const teamId = crypto.randomUUID()
  const deviceId = crypto.randomUUID()
  const publicTokenId = crypto.randomUUID()

  await db.table('sites').insert({
    id: siteId,
    name: 'HB Site',
    code: `hb-site-${Date.now()}`,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })
  await db.table('teams').insert({
    id: teamId,
    site_id: siteId,
    name: 'HB Team',
    code: `hb-team-${Date.now()}`,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })
  await db.table('devices').insert({
    id: deviceId,
    site_id: siteId,
    team_id: teamId,
    name: 'HB Device',
    status: 'UNKNOWN',
    is_managed: false,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })
  await db.table('device_credentials').insert({
    id: crypto.randomUUID(),
    device_id: deviceId,
    credential_hash: await bcrypt.hash(rawSecret, 10),
    public_token_id: publicTokenId,
    issued_at: new Date(),
    created_at: new Date(),
    updated_at: new Date(),
  })

  return { deviceId, publicTokenId }
}

function wsUrl(): string {
  const httpServer = server.getNodeServer()
  const address = httpServer!.address()
  const port = typeof address === 'object' && address ? address.port : 0
  return `http://127.0.0.1:${port}`
}

function connectClient(auth: Record<string, string>): Promise<ClientSocket> {
  return new Promise((resolve, reject) => {
    const socket = ioClient(wsUrl(), { path: '/ws', auth, reconnection: false, timeout: 3000, transports: ['websocket'] })
    socket.on('connect', () => resolve(socket))
    socket.on('connect_error', (err) => reject(err))
  })
}

test.group('WebSocket heartbeat', (group) => {
  let deviceIds: string[] = []

  group.each.teardown(async () => {
    for (const id of deviceIds) {
      await db.from('device_heartbeats').where('device_id', id).delete()
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    deviceIds = []
  })

  test('accepts a heartbeat over the authenticated socket and acknowledges ONLINE', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const ackPromise = new Promise<any>((resolve) => socket.on('device.heartbeat.ack', resolve))
    socket.emit('device.heartbeat', { battery_level: 76, network_type: 'WIFI', connection_state: 'CONNECTED' })
    const ack = await ackPromise

    assert.isTrue(ack.accepted)
    assert.equal(ack.status, 'ONLINE')

    const row = await db.from('device_heartbeats').where('device_id', deviceId).first()
    assert.equal(row.battery_level, 76)
    assert.equal(row.network_type, 'WIFI')

    const device = await db.from('devices').where('id', deviceId).first()
    assert.equal(device.status, 'ONLINE')
    assert.isNotNull(device.last_heartbeat_at)

    socket.disconnect()
  })

  test('handles multiple heartbeats on the same connection', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    for (let i = 0; i < 3; i++) {
      const ackPromise = new Promise<any>((resolve) => socket.once('device.heartbeat.ack', resolve))
      socket.emit('device.heartbeat', { battery_level: 50 + i })
      await ackPromise
    }

    const count = await db.from('device_heartbeats').where('device_id', deviceId).count('* as total')
    assert.equal(Number(count[0].total), 3)

    socket.disconnect()
  })
})
