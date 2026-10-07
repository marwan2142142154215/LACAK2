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

  await db.table('sites').insert({ id: siteId, name: 'Loc Site', code: `los-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('teams').insert({ id: teamId, site_id: siteId, name: 'Loc Team', code: `lot-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('devices').insert({ id: deviceId, site_id: siteId, team_id: teamId, name: 'Loc Device', status: 'ONLINE', is_managed: false, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('device_credentials').insert({ id: crypto.randomUUID(), device_id: deviceId, credential_hash: await bcrypt.hash(rawSecret, 10), public_token_id: publicTokenId, issued_at: new Date(), created_at: new Date(), updated_at: new Date() })

  return { deviceId, publicTokenId }
}

async function seedCommand(deviceId: string) {
  const commandId = crypto.randomUUID()
  await db.table('device_commands').insert({
    id: commandId,
    device_id: deviceId,
    command_type: 'LOCATION_REQUEST',
    idempotency_key: crypto.randomUUID(),
    status: 'PENDING',
    created_by_type: 'SYSTEM',
    expires_at: new Date(Date.now() + 120_000),
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

test.group('Location result capture', (group) => {
  let deviceIds: string[] = []

  group.each.teardown(async () => {
    for (const id of deviceIds) {
      await db.from('device_locations').where('device_id', id).delete()
      await db.from('device_commands').where('device_id', id).delete()
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    deviceIds = []
  })

  test('writes a device_locations row when ack SUCCESS carries a valid result', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId)

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', {
      command_id: commandId,
      status: 'SUCCESS',
      result: { latitude: -6.2, longitude: 106.816666, accuracy: 15.5, source: 'LAST_KNOWN' },
    })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const row = await db.from('device_locations').where('device_id', deviceId).first()
    assert.isNotNull(row)
    assert.approximately(Number(row.latitude), -6.2, 0.0001)
    assert.approximately(Number(row.longitude), 106.816666, 0.0001)
    assert.equal(row.source, 'LAST_KNOWN')
    assert.equal(row.requested_by_command_id, commandId)

    socket.disconnect()
  })

  test('does not write a location row when ack SUCCESS has no result (honest — no fake data)', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId)

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', { command_id: commandId, status: 'FAILED', failure_reason: 'location_available=false' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const count = await db.from('device_locations').where('device_id', deviceId).count('* as total')
    assert.equal(Number(count[0].total), 0)

    const command = await db.from('device_commands').where('id', commandId).first()
    assert.equal(command.status, 'FAILED')
    assert.equal(command.failure_reason, 'location_available=false')

    socket.disconnect()
  })
})
