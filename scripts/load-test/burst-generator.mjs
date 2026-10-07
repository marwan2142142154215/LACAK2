import { io } from 'socket.io-client'
import { readFileSync } from 'node:fs'

const wsUrl = process.argv[2] ?? 'ws://127.0.0.1:3334'
const devices = JSON.parse(readFileSync(process.argv[3] ?? './devices.json', 'utf-8')).slice(1)

let succeeded = 0
let failed = 0

const startedAt = Date.now()
Promise.all(
  devices.map(
    (device) =>
      new Promise((resolve) => {
        const socket = io(wsUrl, { path: '/ws', transports: ['websocket'], auth: device, reconnection: false, timeout: 30000 })
        socket.on('device.connected', () => {
          succeeded++
          resolve()
        })
        socket.on('connect_error', (err) => {
          failed++
          console.error(`connect_error: ${err.message}`)
          resolve()
        })
      }),
  ),
).then(() => {
  console.error(`burst of ${devices.length} done in ${Date.now() - startedAt} ms — succeeded=${succeeded} failed=${failed}`)
  process.exit(failed > 0 ? 1 : 0)
})
