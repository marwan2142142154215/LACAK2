import { test } from '@japa/runner'
import env from '#start/env'

test.group('Internal auth middleware', () => {
  test('rejects request with no secret header', async ({ client }) => {
    const response = await client.get('/api/v1/internal/ping')

    response.assertStatus(401)
    response.assertBodyContains({ success: false })
  })

  test('rejects request with wrong secret', async ({ client }) => {
    const response = await client
      .get('/api/v1/internal/ping')
      .header('x-internal-secret', 'definitely-not-the-secret')

    response.assertStatus(401)
  })

  test('allows request with the correct internal secret', async ({ client }) => {
    const response = await client
      .get('/api/v1/internal/ping')
      .header('x-internal-secret', env.get('GATEWAY_INTERNAL_SECRET'))

    response.assertStatus(200)
    response.assertBodyContains({ success: true, message: 'pong' })
  })
})
