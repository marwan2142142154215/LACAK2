import type { HttpContext } from '@adonisjs/core/http'
import db from '@adonisjs/lucid/services/db'
import redis from '@adonisjs/redis/services/main'

/**
 * GET /health — §52. Dipakai smb-server-launcher (SMB Doctor, §77) dan smb-api untuk
 * mengetahui status AdonisJS. TIDAK memalsukan OK — setiap check benar2 menghubungi service.
 */
export default class HealthController {
  async index({ response }: HttpContext) {
    const checks = {
      adonisjs: { status: 'OK' as const },
      postgresql: await this.checkDatabase(),
      redis: await this.checkRedis(),
    }

    const allOk = Object.values(checks).every((c) => c.status === 'OK')

    return response.status(allOk ? 200 : 503).json({
      success: allOk,
      message: allOk ? 'SYSTEM READY' : 'SYSTEM DEGRADED',
      data: { overall: allOk ? 'READY' : 'DEGRADED', checks },
    })
  }

  private async checkDatabase(): Promise<{ status: 'OK' | 'FAIL'; reason?: string }> {
    try {
      await db.rawQuery('SELECT 1')
      return { status: 'OK' }
    } catch (error) {
      return { status: 'FAIL', reason: error instanceof Error ? error.message : 'unknown error' }
    }
  }

  private async checkRedis(): Promise<{ status: 'OK' | 'FAIL'; reason?: string }> {
    try {
      await redis.ping()
      return { status: 'OK' }
    } catch (error) {
      return { status: 'FAIL', reason: error instanceof Error ? error.message : 'unknown error' }
    }
  }
}
