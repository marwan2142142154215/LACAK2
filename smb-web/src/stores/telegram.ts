import { defineStore } from 'pinia'
import { apiClient } from '@/api/client'
import type { ApiEnvelope, PaginatedEnvelope, User } from '@/types'

export interface TelegramAccount {
  id: string
  telegram_id: number
  telegram_username: string | null
  user_id: string | null
  user?: User
  status: 'PENDING' | 'APPROVED' | 'REVOKED'
  step_up_required: boolean
  approved_at: string | null
  created_at: string
}

export const useTelegramStore = defineStore('telegram', {
  state: () => ({
    list: [] as TelegramAccount[],
    meta: { total: 0, current_page: 1, last_page: 1, per_page: 15 },
    isLoading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(params: { status?: string; page?: number } = {}) {
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.get<PaginatedEnvelope<TelegramAccount>>('/telegram/accounts', { params })
        this.list = data.data
        this.meta = data.meta
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Gagal memuat daftar akun Telegram.'
      } finally {
        this.isLoading = false
      }
    },

    async approve(id: string, userId: string, stepUpRequired: boolean) {
      const { data } = await apiClient.post<ApiEnvelope<TelegramAccount>>(`/telegram/accounts/${id}/approve`, {
        user_id: userId,
        step_up_required: stepUpRequired,
      })
      const idx = this.list.findIndex((a) => a.id === id)
      if (idx !== -1) this.list[idx] = data.data
      return data.data
    },

    async revoke(id: string) {
      const { data } = await apiClient.post<ApiEnvelope<TelegramAccount>>(`/telegram/accounts/${id}/revoke`)
      const idx = this.list.findIndex((a) => a.id === id)
      if (idx !== -1) this.list[idx] = data.data
      return data.data
    },
  },
})
