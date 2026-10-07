import env from '#start/env'
import { defineConfig } from '@adonisjs/redis'
import { InferConnections } from '@adonisjs/redis/types'

/**
 * §42: Redis HANYA untuk cache/presence/ephemeral state. Device presence key
 * (device:presence:{device_id}) dipakai di PHASE 11 (heartbeat) dengan TTL = heartbeat
 * timeout, supaya status ONLINE otomatis "expire" ke tidak-ada kalau device berhenti kirim
 * heartbeat — tanpa perlu job terjadwal yang menyapu seluruh tabel devices.
 */
const redisConfig = defineConfig({
  connection: 'main',
  connections: {
    main: {
      host: env.get('REDIS_HOST'),
      port: env.get('REDIS_PORT'),
      password: env.get('REDIS_PASSWORD') || undefined,
      db: 0,
      keyPrefix: 'smb:',
    },
  },
})

export default redisConfig

declare module '@adonisjs/redis/types' {
  interface RedisConnections extends InferConnections<typeof redisConfig> {}
}
