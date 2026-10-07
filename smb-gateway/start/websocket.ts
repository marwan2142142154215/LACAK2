/*
|--------------------------------------------------------------------------
| WebSocket bootstrap
|--------------------------------------------------------------------------
|
| Socket.IO di-attach ke underlying Node HTTP server yang sama dengan AdonisJS,
| bukan server terpisah (satu port, konsisten dengan wss://ws.lacaksmbbot.com, §43).
|
*/

import app from '@adonisjs/core/services/app'
import server from '@adonisjs/core/services/server'
import websocketService from '#services/websocket_service'

app.ready(() => {
  const httpServer = server.getNodeServer()

  // Di environment 'console' (migration, seed, dst) tidak ada HTTP server — wajar, skip.
  // CATATAN: di test environment, app.ready() menembak SEBELUM test_utils membuat HTTP
  // server sungguhan, jadi httpServer di sini masih null — tests/bootstrap.ts memanggil
  // ulang websocketService.boot() secara eksplisit setelah server benar2 listen (idempotent).
  if (!httpServer) return

  websocketService.boot(httpServer)
})
