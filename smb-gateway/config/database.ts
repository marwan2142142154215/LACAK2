import env from '#start/env'
import { defineConfig } from '@adonisjs/lucid'

/**
 * §34: WAJIB PostgreSQL, database yang SAMA dengan smb-api (Laravel) — AdonisJS tidak
 * punya skema sendiri untuk entitas inti (devices, device_sessions, device_commands, dst).
 * Laravel pemilik migration; AdonisJS hanya membaca/menulis ke tabel yang sama lewat
 * model Lucid yang didefinisikan ulang di sisi sini (lihat app/models/).
 */
export default defineConfig({
  connection: 'postgres',
  connections: {
    postgres: {
      client: 'pg',
      connection: {
        host: env.get('DB_HOST'),
        port: env.get('DB_PORT'),
        user: env.get('DB_USER'),
        password: env.get('DB_PASSWORD'),
        database: env.get('DB_DATABASE'),
      },
      migrations: {
        // AdonisJS TIDAK menjalankan migration sendiri untuk tabel inti — itu tanggung
        // jawab Laravel (§3). Direktori ini kosong sengaja, disediakan jika AdonisJS
        // nanti butuh tabel miliknya sendiri (misal: gateway_nodes untuk multi-instance).
        naturalSort: true,
        paths: ['database/migrations'],
      },
      pool: { min: 2, max: 10 },
      debug: false,
    },
  },
})
