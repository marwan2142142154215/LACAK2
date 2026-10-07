/*
|--------------------------------------------------------------------------
| Environment variables service
|--------------------------------------------------------------------------
|
| The `Env.create` method creates an instance of the Env service. The
| service validates the environment variables and also cast values
| to JavaScript data types.
|
*/

import { Env } from '@adonisjs/core/env'

export default await Env.create(new URL('../', import.meta.url), {
  NODE_ENV: Env.schema.enum(['development', 'production', 'test'] as const),
  PORT: Env.schema.number(),
  APP_KEY: Env.schema.string(),
  HOST: Env.schema.string({ format: 'host' }),
  LOG_LEVEL: Env.schema.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace', 'silent']),

  // --- PostgreSQL (§34 — WAJIB, sumber data sama dengan smb-api/Laravel) --------------
  DB_HOST: Env.schema.string({ format: 'host' }),
  DB_PORT: Env.schema.number(),
  DB_USER: Env.schema.string(),
  DB_PASSWORD: Env.schema.string.optional(),
  DB_DATABASE: Env.schema.string(),

  // --- Redis (§42 — cache/presence/ephemeral state, bukan source of truth) ------------
  REDIS_HOST: Env.schema.string({ format: 'host' }),
  REDIS_PORT: Env.schema.number(),
  REDIS_PASSWORD: Env.schema.string.optional(),

  // --- Internal service-to-service auth dengan Laravel (§3) --------------------------
  // AdonisJS TIDAK membuat command record sendiri — Laravel yang membuatnya, AdonisJS
  // hanya mengirim & melaporkan status. Internal API dipanggil bolak-balik dan harus
  // diautentikasi dengan secret ini (bukan token user), supaya tidak bisa dipalsukan
  // dari luar jaringan server (§39 — jangan hardcode, wajib .env).
  LARAVEL_INTERNAL_URL: Env.schema.string(),
  GATEWAY_INTERNAL_SECRET: Env.schema.string(),

  // --- Device session token signing (§43 — device WAJIB authenticated, bukan device_id saja)
  DEVICE_TOKEN_SECRET: Env.schema.string(),
})
