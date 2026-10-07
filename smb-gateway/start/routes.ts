/*
|--------------------------------------------------------------------------
| Routes file
|--------------------------------------------------------------------------
*/

import router from '@adonisjs/core/services/router'
import { middleware } from '#start/kernel'
const HealthController = () => import('#controllers/health_controller')
const InternalPingController = () => import('#controllers/internal_ping_controller')
const InternalCommandsController = () => import('#controllers/internal_commands_controller')

// §52: health check publik (dipakai dashboard/launcher), tidak butuh auth.
router.get('/health', [HealthController, 'index'])

// §3/§36: seluruh API AdonisJS juga di bawah /api/v1 untuk konsistensi dengan Laravel.
router
  .group(() => {
    router.get('/health', [HealthController, 'index'])

    // Rute internal (dipanggil Laravel: command.created, dst) ditambahkan progresif di
    // PHASE 8 (WebSocket) dan PHASE 12 (command broker) — lihat README.md root.
    router
      .group(() => {
        router.get('/ping', [InternalPingController, 'index'])
        router.post('/commands/dispatch', [InternalCommandsController, 'dispatch'])
      })
      .prefix('/internal')
      .use(middleware.internalAuth())
  })
  .prefix('/api/v1')
