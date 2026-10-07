import { test } from '@japa/runner'
import { io as ioClient, type Socket as ClientSocket } from 'socket.io-client'
import crypto from 'node:crypto'
import bcrypt from 'bcrypt'
import db from '@adonisjs/lucid/services/db'
import server from '@adonisjs/core/services/server'
import websocketService from '#services/websocket_service'

async function seedDevice(rawSecret: string) {
  const siteId = crypto.randomUUID()
  const teamId = crypto.randomUUID()
  const deviceId = crypto.randomUUID()
  const publicTokenId = crypto.randomUUID()
  const suffix = crypto.randomUUID().slice(0, 12)

  await db.table('sites').insert({ id: siteId, name: 'Cam Site', code: `cas-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('teams').insert({ id: teamId, site_id: siteId, name: 'Cam Team', code: `cat-${suffix}`, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('devices').insert({ id: deviceId, site_id: siteId, team_id: teamId, name: 'Cam Device', status: 'ONLINE', is_managed: false, is_active: true, created_at: new Date(), updated_at: new Date() })
  await db.table('device_credentials').insert({ id: crypto.randomUUID(), device_id: deviceId, credential_hash: await bcrypt.hash(rawSecret, 10), public_token_id: publicTokenId, issued_at: new Date(), created_at: new Date(), updated_at: new Date() })

  return { deviceId, publicTokenId }
}

async function seedCommand(deviceId: string, payload: Record<string, unknown>) {
  const commandId = crypto.randomUUID()
  await db.table('device_commands').insert({
    id: commandId,
    device_id: deviceId,
    command_type: 'CAMERA_REQUEST',
    payload: JSON.stringify(payload),
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

test.group('Camera result capture', (group) => {
  let deviceIds: string[] = []

  group.each.teardown(async () => {
    for (const id of deviceIds) {
      await db.from('device_media').where('device_id', id).delete()
      await db.from('device_commands').where('device_id', id).delete()
      await db.from('device_credentials').where('device_id', id).delete()
      await db.from('devices').where('id', id).delete()
    }
    deviceIds = []
  })

  test('writes a device_media row using storage_path/camera_facing from the command payload + metadata from the ack result', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const storagePath = `devices/${deviceId}/media/photo.jpg`
    const commandId = await seedCommand(deviceId, { camera_facing: 'FRONT', storage_path: storagePath, upload_url: 'https://example.test/put' })

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', {
      command_id: commandId,
      status: 'SUCCESS',
      result: { mime_type: 'image/jpeg', size_bytes: 204800, sha256_hash: 'a'.repeat(64) },
    })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const row = await db.from('device_media').where('device_id', deviceId).first()
    assert.isNotNull(row)
    assert.equal(row.storage_path, storagePath)
    assert.equal(row.camera_facing, 'FRONT')
    assert.equal(row.mime_type, 'image/jpeg')
    assert.equal(row.size_bytes, 204800)
    assert.equal(row.command_id, commandId)

    socket.disconnect()
  })

  test('does not write a media row when the device reports CAMERA_UNAVAILABLE (FAILED, no result)', async ({ assert }) => {
    const { deviceId, publicTokenId } = await seedDevice('secret')
    deviceIds.push(deviceId)
    const commandId = await seedCommand(deviceId, { camera_facing: 'BACK', storage_path: 'x.jpg', upload_url: 'https://example.test/put' })

    const socket = await connectClient({ device_id: deviceId, public_token_id: publicTokenId, device_secret: 'secret' })
    await new Promise((resolve) => socket.on('device.connected', resolve))

    const createdPromise = new Promise((resolve) => socket.on('device.command.created', resolve))
    await websocketService.dispatchCommand(commandId)
    await createdPromise

    socket.emit('device.command.ack', { command_id: commandId, status: 'FAILED', failure_reason: 'CAMERA_UNAVAILABLE' })
    await new Promise((resolve) => setTimeout(resolve, 200))

    const count = await db.from('device_media').where('device_id', deviceId).count('* as total')
    assert.equal(Number(count[0].total), 0)

    socket.disconnect()
  })
})
