import { test } from '@japa/runner'
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client'
import crypto from 'node:crypto'
import bcrypt from 'bcryptjs'
import db from '@adonisjs/lucid/services/db'
import server from '@adonisjs/core/services/server'
import websocketService from '#services/websocket_service'

async function seedDevice(rawSecret: string) {
  const siteId = crypto.randomUUID()
  const teamId = crypto.randomUUID()
  const deviceId = crypto.randomUUID()
  const publicTokenId = crypto.randomUUID()

  const suffix = crypto.randomUUID().slice(0, 12)
  await db.table('sites').insert({ id: siteId, name: 'CMD Site', code: `cs-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('teams').insert({ id: teamId, site_id: siteId, name: 'CMD Team', code: `ct-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('devices').insert({ id: deviceId, site_id: siteId, team_id: teamId, name: 'CMD Device', status: 'UNKNOWN', is_managed: false, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('device_credentials').insert({ id: crypto.randomUUID(), device_id: deviceId, credential_hash: await bcrypt.hash(rawSecret, 10), public_token_id: publicTokenId, issued_at: new Date(), created_at: new Date(), updated_at: new Date() })

  return { deviceId, publicTokenId }
}

async function seedCommand(deviceId: string, opts: { status?: string; expiresInSeconds?: number } = {}) {
  const commandId = crypto.randomUUID()
  await db.table('device_commands').insert({
    id: commandId,
    device_id: deviceId,
    command_type: 'LOCATION_REQUEST',
    idempotency_key: crypto.randomUUID(),
    status: opts.status ?? 'PENDING',
    created_by_type: 'SYSTEM',
    expires_at: new Date(Date.now() + (opts.expiresInSeconds ?? 120) * 1000),
    created_at: new Date(),
    updated_at: new Date(),
  })
  return commandId
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

test.group('Command broker', (group) => {
  let deviceIds: string[] = []

  group.each.teardown(async () => {
    for (const id of deviceIds) {
      await db.from('device_command_logs').whereIn('command_id', db.from('device_commands').where('device_id', id).select('id')).delete()
      await db.from('device_commands').where('device_id', id).delete()
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    deviceIds = []
  })

  test('dispatches a pending command to a connected device and tracks SENT status', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId)

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise<any>((resolve) => socket.on('device.command.created', resolve))
    const result = await websocketService.dispatchCommand(commandId)
    assert.isTrue(result.dispatched)

    const created = await createdPromise
    assert.equal(created.command_id, commandId)
    assert.equal(created.command_type, 'LOCATION_REQUEST')

    const row = await db.from('device_commands').where('id', commandId).first()
    assert.equal(row.status, 'SENT')
    assert.isNotNull(row.sent_at)

    socket.disconnect()
  })

  test('does not dispatch to a disconnected device', async ({ assert }) => {
    const { deviceId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId)

    const result = await websocketService.dispatchCommand(commandId)
    assert.isFalse(result.dispatched)
    assert.equal(result.reason, 'device_not_connected')

    const row = await db.from('device_commands').where('id', commandId).first()
    assert.equal(row.status, 'PENDING')
  })

  test('marks an already-expired command EXPIRED instead of dispatching it', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId, { expiresInSeconds: -10 })

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const result = await websocketService.dispatchCommand(commandId)
    assert.isFalse(result.dispatched)
    assert.equal(result.reason, 'expired')

    const row = await db.from('device_commands').where('id', commandId).first()
    assert.equal(row.status, 'EXPIRED')

    socket.disconnect()
  })

  test('accepts a valid device ack and progresses the status forward', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId)

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', { command_id: commandId, status: 'SUCCESS' })
    await new Promise((resolve) => setTimeout(resolve, 200)) // ack diproses async, tidak ada response langsung

    const row = await db.from('device_commands').where('id', commandId).first()
    assert.equal(row.status, 'SUCCESS')
    assert.isNotNull(row.completed_at)

    const logs = await db.from('device_command_logs').where('command_id', commandId)
    assert.isAtLeast(logs.length, 1)

    socket.disconnect()
  })

  test('rejects an ack for a command belonging to a different device (§21 anti wrong-device)', async ({ assert }) => {
    const deviceA = await seedDevice('secret-a')
    const deviceB = await seedDevice('secret-b')
    deviceIds.push(deviceA.deviceId, deviceB.deviceId)
    const commandForB = await seedCommand(deviceB.deviceId)

    const socketA = await connectClient({
      device_id: deviceA.deviceId,
      public_token_id: deviceA.publicTokenId,
      device_secret: 'secret-a',
    })
    await new Promise((resolve) => socketA.on('device.connected', resolve))

    // Device A mencoba meng-ack command yang sebenarnya milik device B.
    socketA.emit('device.command.ack', { command_id: commandForB, status: 'SUCCESS' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const row = await db.from('device_commands').where('id', commandForB).first()
    assert.equal(row.status, 'PENDING') // TIDAK berubah — ack ditolak

    socketA.disconnect()
  })

  test('rejects a backward or invalid status transition from device ack', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId, { status: 'SUCCESS' })

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    // Command sudah SUCCESS (terminal) — device mencoba "mundur" ke EXECUTING, harus ditolak.
    socket.emit('device.command.ack', { command_id: commandId, status: 'EXECUTING' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const row = await db.from('device_commands').where('id', commandId).first()
    assert.equal(row.status, 'SUCCESS') // tetap, tidak berubah

    socket.disconnect()
  })
})
