import { defineStore } from 'pinia'
import { apiClient, getStoredToken, setStoredToken } from '@/api/client'
import type { ApiEnvelope, User } from '@/types'

interface LoginResponse {
  two_factor_required?: boolean
  login_token?: string
  token?: string
  user?: User
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: getStoredToken() as string | null,
    user: null as User | null,
    pendingLoginToken: null as string | null,
    isLoading: false,
    error: null as string | null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    permissions: (state) => new Set(state.user?.permissions ?? []),
  },

  actions: {
    can(permission: string): boolean {
      return this.permissions.has(permission) || this.user?.roles.includes('SUPER_ADMIN') === true
    },

    /** §4 login — bisa berujung butuh 2FA (two_factor_required) atau langsung sukses. */
    async login(email: string, password: string): Promise<'ok' | 'needs_2fa'> {
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.post<ApiEnvelope<LoginResponse>>('/auth/login', {
          email,
          password,
          device_name: 'SMB Web Dashboard',
        })

        if (data.data.two_factor_required) {
          this.pendingLoginToken = data.data.login_token ?? null
          return 'needs_2fa'
        }

        this.applySession(data.data.token!, data.data.user!)
        return 'ok'
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Login gagal. Periksa email/password.'
        throw err
      } finally {
        this.isLoading = false
      }
    },

    async verifyTwoFactor(code: string): Promise<void> {
      if (!this.pendingLoginToken) throw new Error('Tidak ada sesi login 2FA yang aktif.')
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.post<ApiEnvelope<LoginResponse>>('/auth/two-factor-challenge', {
          login_token: this.pendingLoginToken,
          code,
          device_name: 'SMB Web Dashboard',
        })
        this.applySession(data.data.token!, data.data.user!)
        this.pendingLoginToken = null
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Kode 2FA salah.'
        throw err
      } finally {
        this.isLoading = false
      }
    },

    async fetchMe(): Promise<void> {
      const { data } = await apiClient.get<ApiEnvelope<User>>('/auth/me')
      this.user = data.data
    },

    /**
     * §14/§30: sesi LOKAL harus selalu berhasil terhapus — kalau request revoke token ke
     * server gagal (network turun, token sudah expired/invalid di server), itu TIDAK boleh
     * menggagalkan logout dari sisi user (yang sudah ingin keluar). Error ditelan dengan
     * sengaja SETELAH sesi lokal dibersihkan — pemanggil (mis. AppLayout.vue) tetap bisa
     * `await` lalu redirect ke /login tanpa perlu try/catch sendiri. Bug nyata yang
     * ditemukan sebelum fix ini: `await auth.logout()` melempar exception kalau request
     * gagal, membuat baris `router.push({ name: 'login' })` sesudahnya TIDAK PERNAH
     * dijalankan — user macet di halaman yang sudah tidak punya token valid.
     */
    async logout(): Promise<void> {
      try {
        await apiClient.post('/auth/logout')
      } catch (err) {
        console.warn('Logout server-side gagal (sesi lokal tetap dihapus):', err)
      } finally {
        this.applySession(null, null)
      }
    },

    applySession(token: string | null, user: User | null) {
      this.token = token
      this.user = user
      setStoredToken(token)
    },
  },
})
