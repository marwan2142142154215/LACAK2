import { test } from '@japa/runner'

test.group('Health check', () => {
  test('GET /health reports real Postgres and Redis connectivity', async ({ client, assert }) => {
    const response = await client.get('/health')

    response.assertStatus(200)
    const body = response.body()

    assert.isTrue(body.success)
    assert.equal(body.data.overall, 'READY')
    assert.equal(body.data.checks.postgresql.status, 'OK')
    assert.equal(body.data.checks.redis.status, 'OK')
  })

  test('GET /api/v1/health mirrors the same checks under the versioned prefix', async ({
    client,
    assert,
  }) => {
    const response = await client.get('/api/v1/health')

    response.assertStatus(200)
    assert.isTrue(response.body().success)
  })
})
