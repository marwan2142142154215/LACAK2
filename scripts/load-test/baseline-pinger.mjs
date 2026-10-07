import { io } from 'socket.io-client'
import { readFileSync } from 'node:fs'

const wsUrl = process.argv[2] ?? 'ws://127.0.0.1:3334'
const devices = JSON.parse(readFileSync(process.argv[3] ?? './devices.json', 'utf-8'))
const baseline = devices[0]

const socket = io(wsUrl, { path: '/ws', transports: ['websocket'], auth: baseline, reconnection: false })
socket.on('device.connected', () => {
  console.error('baseline connected, pinging every 500ms for 40s')
  const interval = setInterval(() => {
    const startedAt = Date.now()
    socket.emit('device.heartbeat', { request_id: 'baseline', recorded_at: new Date().toISOString() })
    socket.once('device.heartbeat.ack', () => {
      console.log(Date.now() - startedAt)
    })
  }, 500)
  setTimeout(() => {
    clearInterval(interval)
    socket.disconnect()
    process.exit(0)
  }, 40000)
})
