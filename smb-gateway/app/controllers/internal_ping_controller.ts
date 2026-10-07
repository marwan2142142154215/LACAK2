import type { HttpContext } from '@adonisjs/core/http'

/**
 * GET /api/v1/internal/ping — dipakai Laravel untuk verifikasi bahwa AdonisJS gateway
 * hidup DAN mengenali internal secret yang sama (§3). Endpoint nyata pertama di balik
 * InternalAuthMiddleware — command.created dkk menyusul di PHASE 8/12.
 */
export default class InternalPingController {
  async index({ response }: HttpContext) {
    return response.ok({
      success: true,
      message: 'pong',
      data: { service: 'smb-gateway', time: new Date().toISOString() },
    })
  }
}
