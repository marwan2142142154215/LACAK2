// Verifikasi TAMBAHAN (bukan load test agregat): apakah event loop AdonisJS tetap
// responsif untuk device yang SUDAH terhubung, SELAMA badai auth bcrypt dari banyak
// device baru berlangsung. Ini mengukur klaim nyata dari fix bcryptjs->bcrypt native:
// bukan "koneksi baru jadi lebih cepat" (itu tetap dibatasi CPU 4-core, lihat laporan),
// tapi "device yang sudah online tidak ikut macet gara-gara device lain connect".
import { io } from 'socket.io-client'
import { readFileSync } from 'node:fs'

const wsUrl = process.argv[2] ?? 'ws://127.0.0.1:3334'
const devices = JSON.parse(readFileSync(process.argv[3] ?? './devices.json', 'utf-8'))

const baseline = devices[0]
const burstDevices = devices.slice(1)

const baselineLatencies = []

function connectBaseline() {
  return new Promise((resolve, reject) => {
    const socket = io(wsUrl, {
      path: '/ws',
      transports: ['websocket'],
      auth: baseline,
      reconnection: false,
    })
    socket.on('device.connected', () => resolve(socket))
    socket.on('connect_error', reject)
  })
}

function pingHeartbeat(socket) {
  return new Promise((resolve) => {
    const startedAt = Date.now()
    socket.emit('device.heartbeat', { request_id: 'baseline', recorded_at: new Date().toISOString() })
    socket.once('device.heartbeat.ack', () => {
      baselineLatencies.push(Date.now() - startedAt)
      resolve()
    })
  })
}

function connectBurstDevice(device) {
  return new Promise((resolve) => {
    const socket = io(wsUrl, { path: '/ws', transports: ['websocket'], auth: device, reconnection: false, timeout: 15000 })
    socket.on('device.connected', () => resolve())
    socket.on('connect_error', () => resolve())
  })
}

async function main() {
  console.log(`Baseline device: ${baseline.device_id}`)
  const baselineSocket = await connectBaseline()
  console.log('Baseline connected. Mengukur heartbeat latency SEBELUM badai...')
  await pingHeartbeat(baselineSocket)
  console.log(`  baseline (tenang): ${baselineLatencies[0]} ms`)

  console.log(`Melepas badai ${burstDevices.length} koneksi baru bersamaan, sambil terus heartbeat baseline setiap 500ms...`)
  const heartbeatInterval = setInterval(() => pingHeartbeat(baselineSocket), 500)

  const burstStart = Date.now()
  await Promise.all(burstDevices.map(connectBurstDevice))
  const burstDurationMs = Date.now() - burstStart

  clearInterval(heartbeatInterval)
  await new Promise((r) => setTimeout(r, 600)) // beri waktu heartbeat terakhir selesai

  console.log('')
  console.log(`Badai ${burstDevices.length} koneksi selesai dalam ${burstDurationMs} ms.`)
  console.log(`Heartbeat latency baseline SELAMA badai (ms): ${baselineLatencies.slice(1).join(', ')}`)
  console.log(`  min/max selama badai: ${Math.min(...baselineLatencies.slice(1))}/${Math.max(...baselineLatencies.slice(1))} ms`)

  baselineSocket.disconnect()
  process.exit(0)
}

main()
