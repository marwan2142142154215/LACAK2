import type { HttpContext } from '@adonisjs/core/http'
import type { NextFn } from '@adonisjs/core/types/http'
import env from '#start/env'
import crypto from 'node:crypto'

/**
 * §3/§39: endpoint internal yang dipanggil oleh Laravel (bukan oleh device/dashboard/
 * Telegram langsung) HARUS diautentikasi dengan secret bersama — bukan dibiarkan terbuka
 * hanya karena "cuma dipanggil dari server sendiri". Tanpa ini, siapa pun yang bisa
 * mencapai port 3333 bisa memicu command push ke device mana pun (§21 command security).
 *
 * Dibandingkan secara timing-safe (crypto.timingSafeEqual) untuk mencegah timing attack
 * saat membandingkan secret.
 */
export default class InternalAuthMiddleware {
  async handle({ request, response }: HttpContext, next: NextFn) {
    const provided = request.header('x-internal-secret') ?? ''
    const expected = env.get('GATEWAY_INTERNAL_SECRET')

    const providedBuf = Buffer.from(provided)
    const expectedBuf = Buffer.from(expected)

    const valid =
      providedBuf.length === expectedBuf.length &&
      providedBuf.length > 0 &&
      crypto.timingSafeEqual(providedBuf, expectedBuf)

    if (!valid) {
      return response.unauthorized({
        success: false,
        message: 'Internal secret tidak valid.',
        errors: [],
      })
    }

    return next()
  }
}
