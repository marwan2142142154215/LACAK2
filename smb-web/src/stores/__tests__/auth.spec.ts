import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// §9/§30 — mock axios client, bukan server sungguhan: test ini memverifikasi logic store
// (step-up 2FA, error handling, permission check), bukan koneksi network.
vi.mock('@/api/client', () => ({
  apiClient: { post: vi.fn(), get: vi.fn() },
  getStoredToken: vi.fn(() => null),
  setStoredToken: vi.fn(),
}))

import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('returns "ok" and applies the session immediately when 2FA is not required', async () => {
    ;(apiClient.post as any).mockResolvedValue({
      data: { data: { token: 'tok-123', user: { id: '1', name: 'Admin', roles: ['ADMIN'], permissions: [] } } },
    })

    const store = useAuthStore()
    const result = await store.login('admin@example.com', 'secret')

    expect(result).toBe('ok')
    expect(store.token).toBe('tok-123')
    expect(store.isAuthenticated).toBe(true)
  })

  it('returns "needs_2fa" and stores the login_token WITHOUT authenticating yet (§30 step-up)', async () => {
    ;(apiClient.post as any).mockResolvedValue({
      data: { data: { two_factor_required: true, login_token: 'challenge-abc' } },
    })

    const store = useAuthStore()
    const result = await store.login('admin@example.com', 'secret')

    expect(result).toBe('needs_2fa')
    expect(store.pendingLoginToken).toBe('challenge-abc')
    expect(store.isAuthenticated).toBe(false) // belum punya token nyata
  })

  it('surfaces the server error message on failed login instead of a generic one', async () => {
    ;(apiClient.post as any).mockRejectedValue({ response: { data: { message: 'Email atau password salah.' } } })

    const store = useAuthStore()
    await expect(store.login('x@example.com', 'wrong')).rejects.toBeTruthy()
    expect(store.error).toBe('Email atau password salah.')
    expect(store.isAuthenticated).toBe(false)
  })

  it('rejects verifyTwoFactor when there is no pending login_token', async () => {
    const store = useAuthStore()
    await expect(store.verifyTwoFactor('123456')).rejects.toThrow('Tidak ada sesi login 2FA yang aktif.')
    expect(apiClient.post).not.toHaveBeenCalled()
  })

  it('completes the session after a valid 2FA code and clears the pending token', async () => {
    const store = useAuthStore()
    store.pendingLoginToken = 'challenge-abc'
    ;(apiClient.post as any).mockResolvedValue({
      data: { data: { token: 'tok-456', user: { id: '1', name: 'Admin', roles: ['ADMIN'], permissions: [] } } },
    })

    await store.verifyTwoFactor('123456')

    expect(store.token).toBe('tok-456')
    expect(store.pendingLoginToken).toBeNull()
  })

  it('can() grants access via an explicit permission', () => {
    const store = useAuthStore()
    store.user = { id: '1', name: 'Op', roles: ['OPERATOR'], permissions: ['devices.lock'] } as any

    expect(store.can('devices.lock')).toBe(true)
    expect(store.can('devices.camera')).toBe(false)
  })

  it('can() bypasses granular permissions entirely for SUPER_ADMIN (§31 Gate::before)', () => {
    const store = useAuthStore()
    store.user = { id: '1', name: 'Root', roles: ['SUPER_ADMIN'], permissions: [] } as any

    expect(store.can('anything.not.listed')).toBe(true)
  })

  it('logout clears the local session WITHOUT rejecting even if the server request fails (real bug fixed: a caller doing `await auth.logout(); router.push(...)` must not get stuck)', async () => {
    const store = useAuthStore()
    store.token = 'tok-789'
    store.user = { id: '1', name: 'Admin', roles: ['ADMIN'], permissions: [] } as any
    ;(apiClient.post as any).mockRejectedValue(new Error('network error'))

    await expect(store.logout()).resolves.toBeUndefined()

    expect(store.token).toBeNull()
    expect(store.user).toBeNull()
  })
})
