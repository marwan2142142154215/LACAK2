import { test } from '@japa/runner'
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client'
import crypto from 'node:crypto'
import bcrypt from 'bcrypt'
import db from '@adonisjs/lucid/services/db'
import server from '@adonisjs/core/services/server'

/**
 * §97-109 — jalur UTAMA (WebSocket heartbeat) untuk deteksi network policy violation.
 * Port TypeScript dari tests/Feature/NetworkViolationTest.php (Laravel) — perilaku
 * dedup/alert HARUS identik, keduanya menulis tabel `device_network_violations` yang
 * sama (§34).
 */
async function seedPolicedDevice(rawSecret: string, allowedIp: string | null) {
  const siteId = crypto.randomUUID()
  const teamId = crypto.randomUUID()
  const deviceId = crypto.randomUUID()
  const publicTokenId = crypto.randomUUID()

  await db.table('sites').insert({
    id: siteId,
    name: 'NV Site',
    code: `nv-site-${Date.now()}`,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })
  await db.table('teams').insert({
    id: teamId,
    site_id: siteId,
    name: 'NV Team',
    code: `nv-team-${Date.now()}`,
    is_active: true,
    created_at: new Date(),
    updated_at: new Date(),
  })
  await db.table('devices').insert({
    id: deviceId,
    site_id: siteId,
    team_id: teamId,
    name: 'NV Device',
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

  if (allowedIp) {
    await db.table('site_network_policies').insert({
      id: crypto.randomUUID(),
      site_id: siteId,
      network_type: 'IP',
      value: allowedIp,
      is_active: true,
      created_at: new Date(),
      updated_at: new Date(),
    })
  }

  return { deviceId, siteId, publicTokenId }
}

function wsUrl(): string {
  const httpServer = server.getNodeServer()
  const address = httpServer!.address()
  const port = typeof address === 'object' && address ? address.port : 0
  return `http://127.0.0.1:${port}`
}

function connectClient(auth: Record<string, string>): Promise<ClientSocket> {
  return new Promise((resolve, reject) => {
    const socket = ioClient(wsUrl(), {
      path: '/ws',
      auth,
      reconnection: false,
      timeout: 3000,
      transports: ['websocket'],
    })
    socket.on('connect', () => resolve(socket))
    socket.on('connect_error', (err) => reject(err))
  })
}

test.group('Network policy violation (WS heartbeat path)', (group) => {
  let deviceIds: string[] = []
  let siteIds: string[] = []

  group.each.teardown(async () => {
    for (const id of deviceIds) {
      await db.from('device_network_violations').where('device_id', id).delete()
      await db.from('device_heartbeats').where('device_id', id).delete()
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    for (const id of siteIds) {
      await db.from('site_network_policies').where('site_id', id).delete()
    }
    deviceIds = []
    siteIds = []
  })

  test('reports ALLOWED and creates no violation when no policy is configured for the site (default-open)', async ({
    assert,
  }) => {
    const { deviceId, siteId, publicTokenId } = await seedPolicedDevice('secret', null)
    deviceIds.push(deviceId)
    siteIds.push(siteId)

    const socket = await connectClient({
      device_id: deviceId,
      public_token_id: publicTokenId,
      device_secret: 'secret',
    })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const ackPromise = new Promise<any>((resolve) => socket.on('device.heartbeat.ack', resolve))
    socket.emit('device.heartbeat', { battery_level: 80 })
    const ack = await ackPromise

    assert.equal(ack.network_status, 'ALLOWED')
    const count = await db
      .from('device_network_violations')
      .where('device_id', deviceId)
      .count('* as total')
    assert.equal(Number(count[0].total), 0)

    socket.disconnect()
  })

  test('reports BLOCKED and creates exactly one violation row across multiple heartbeats from a disallowed IP (dedup, §105)', async ({
    assert,
  }) => {
    // Site whitelist hanya mengizinkan "203.0.113.10" — test ini connect dari loopback
    // (127.0.0.1/::1), yang TIDAK ada di whitelist, jadi setiap heartbeat harus BLOCKED.
    const { deviceId, siteId, publicTokenId } = await seedPolicedDevice('secret', '203.0.113.10')
    deviceIds.push(deviceId)
    siteIds.push(siteId)

    const socket = await connectClient({
      device_id: deviceId,
      public_token_id: publicTokenId,
      device_secret: 'secret',
    })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    for (let i = 0; i < 3; i++) {
      const ackPromise = new Promise<any>((resolve) => socket.once('device.heartbeat.ack', resolve))
      socket.emit('device.heartbeat', { battery_level: 80 })
      const ack = await ackPromise
      assert.equal(ack.network_status, 'BLOCKED')
    }

    const violations = await db.from('device_network_violations').where('device_id', deviceId)
    assert.lengthOf(violations, 1)
    assert.isNull(violations[0].resolved_at)

    socket.disconnect()
  })
})
