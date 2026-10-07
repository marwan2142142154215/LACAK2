// Load test NYATA (bukan simulasi payload palsu) untuk smb-gateway — setiap "device" di
// sini adalah koneksi Socket.IO sungguhan memakai `socket.io-client`, dengan handshake
// auth (device_id/public_token_id/device_secret) ASLI dari device yang benar2 ada di DB
// (lihat seed-devices.php). PHASE 24, §41.
//
// Usage: node device-heartbeat-load.mjs <gatewayWsUrl> <devicesJsonPath> [rampDelayMs]
// Contoh: node device-heartbeat-load.mjs ws://127.0.0.1:3334 ./devices.json 20

import { io } from 'socket.io-client'
import { readFileSync } from 'node:fs'

const wsUrl = process.argv[2] ?? 'ws://127.0.0.1:3334'
const devicesPath = process.argv[3] ?? './devices.json'
const rampDelayMs = Number(process.argv[4] ?? 20)

const devices = JSON.parse(readFileSync(devicesPath, 'utf-8'))

let connected = 0
let connectErrors = 0
let heartbeatAcks = 0
const connectLatenciesMs = []
const heartbeatLatenciesMs = []
const sockets = []

function connectDevice(device, index) {
  return new Promise((resolve) => {
    const startedAt = Date.now()
    const socket = io(wsUrl, {
      path: '/ws',
      transports: ['websocket'],
      auth: {
        device_id: device.device_id,
        public_token_id: device.public_token_id,
        device_secret: device.device_secret,
      },
      reconnection: false,
      timeout: 8000,
    })
    sockets.push(socket)

    socket.on('device.connected', () => {
      connected++
      connectLatenciesMs.push(Date.now() - startedAt)

      const hbStartedAt = Date.now()
      socket.emit('device.heartbeat', {
        request_id: `loadtest-${index}`,
        recorded_at: new Date().toISOString(),
        battery_level: 80,
        network_type: 'WIFI',
        connection_state: 'CONNECTED',
        app_version: '1.0.0-loadtest',
        android_version: '14',
      })

      socket.once('device.heartbeat.ack', () => {
        heartbeatAcks++
        heartbeatLatenciesMs.push(Date.now() - hbStartedAt)
        resolve()
      })

      setTimeout(resolve, 5000) // jangan macet selamanya kalau ack tidak pernah datang
    })

    socket.on('connect_error', (err) => {
      connectErrors++
      console.error(`[device ${index}] connect_error: ${err.message}`)
      resolve()
    })
  })
}

function percentile(arr, p) {
  if (arr.length === 0) return null
  const sorted = [...arr].sort((a, b) => a - b)
  const idx = Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))
  return sorted[idx]
}

async function main() {
  console.log(`Load test: ${devices.length} device -> ${wsUrl} (ramp ${rampDelayMs}ms/device)`)
  const tasks = []
  for (let i = 0; i < devices.length; i++) {
    tasks.push(connectDevice(devices[i], i))
    await new Promise((r) => setTimeout(r, rampDelayMs)) // §42: ramp bertahap, bukan sekaligus (anti reconnect-storm pada diri sendiri)
  }
  await Promise.all(tasks)

  console.log('')
  console.log('=== HASIL (nyata, bukan perkiraan) ===')
  console.log(`Device dicoba          : ${devices.length}`)
  console.log(`Berhasil connect       : ${connected}`)
  console.log(`Gagal connect          : ${connectErrors}`)
  console.log(`Heartbeat ack diterima : ${heartbeatAcks}`)
  console.log(`Connect latency  p50/p95/max (ms): ${percentile(connectLatenciesMs, 50)}/${percentile(connectLatenciesMs, 95)}/${Math.max(...connectLatenciesMs, 0)}`)
  console.log(`Heartbeat latency p50/p95/max (ms): ${percentile(heartbeatLatenciesMs, 50)}/${percentile(heartbeatLatenciesMs, 95)}/${Math.max(...heartbeatLatenciesMs, 0)}`)

  sockets.forEach((s) => s.disconnect())
  process.exit(connectErrors > 0 ? 1 : 0)
}

main()
