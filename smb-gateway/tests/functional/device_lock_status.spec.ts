import { test } from '@japa/runner'
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client'
import crypto from 'node:crypto'
import bcrypt from 'bcrypt'
import { DateTime } from 'luxon'
import db from '@adonisjs/lucid/services/db'
import server from '@adonisjs/core/services/server'
import websocketService from '#services/websocket_service'

async function seedDevice(rawSecret: string, lastHeartbeatAt: Date | null = null) {
  const siteId = crypto.randomUUID()
  const teamId = crypto.randomUUID()
  const deviceId = crypto.randomUUID()
  const publicTokenId = crypto.randomUUID()
  const suffix = crypto.randomUUID().slice(0, 12)

  await db.table('sites').insert({ id: siteId, name: 'Lock Site', code: `ls-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('teams').insert({ id: teamId, site_id: siteId, name: 'Lock Team', code: `lt-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('devices').insert({
    id: deviceId,
    site_id: siteId,
    team_id: teamId,
    name: 'Lock Device',
    status: 'ONLINE',
    is_managed: false,
    is_active: true,
    last_heartbeat_at: lastHeartbeatAt,
    created_at: new Date(),
    updated_at: new Date(),
  })
  await db.table('device_credentials').insert({ id: crypto.randomUUID(), device_id: deviceId, credential_hash: await bcrypt.hash(rawSecret, 10), public_token_id: publicTokenId, issued_at: new Date(), created_at: new Date(), updated_at: new Date() })

  return { deviceId, publicTokenId }
}

async function seedCommand(deviceId: string, commandType: string) {
  const commandId = crypto.randomUUID()
  await db.table('device_commands').insert({
    id: commandId,
    device_id: deviceId,
    command_type: commandType,
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

test.group('Device status follows LOCK/UNLOCK command success', (group) => {
  let deviceIds: string[] = []

  group.each.teardown(async () => {
    for (const id of deviceIds) {
      await db.from('device_commands').where('device_id', id).delete()
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    deviceIds = []
  })

  test('sets device.status to LOCKED when a LOCK command succeeds', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId, 'LOCK')

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', { command_id: commandId, status: 'SUCCESS' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const device = await db.from('devices').where('id', deviceId).first()
    assert.equal(device.status, 'LOCKED')

    socket.disconnect()
  })

  test('restores device.status from heartbeat recency when UNLOCK succeeds', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret', DateTime.now().minus({ seconds: 10 }).toJSDate())
    deviceIds.push(deviceId)
    await db.from('devices').where('id', deviceId).update({ status: 'LOCKED' })
    const commandId = await seedCommand(deviceId, 'UNLOCK')

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', { command_id: commandId, status: 'SUCCESS' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const device = await db.from('devices').where('id', deviceId).first()
    assert.equal(device.status, 'ONLINE') // heartbeat 10s lalu -> ONLINE, bukan LOCKED lagi

    socket.disconnect()
  })

  test('does not change device.status when a LOCK command only reaches EXECUTING (not yet SUCCESS)', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId, 'LOCK')

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', { command_id: commandId, status: 'EXECUTING' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const device = await db.from('devices').where('id', deviceId).first()
    assert.equal(device.status, 'ONLINE') // belum SUCCESS -> status TIDAK berubah ke LOCKED (§66)

    socket.disconnect()
  })
})
