import { defineStore } from 'pinia'
import { apiClient } from '@/api/client'
import type { ApiEnvelope, PaginatedEnvelope, Team } from '@/types'

export const useTeamStore = defineStore('teams', {
  state: () => ({
    list: [] as Team[],
    meta: { total: 0, current_page: 1, last_page: 1, per_page: 15 },
    isLoading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(params: { site_id?: string; page?: number } = {}) {
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.get<PaginatedEnvelope<Team>>('/teams', { params })
        this.list = data.data
        this.meta = data.meta
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Gagal memuat daftar tim.'
      } finally {
        this.isLoading = false
      }
    },

    async create(payload: { site_id: string; name: string; code: string }) {
      const { data } = await apiClient.post<ApiEnvelope<Team>>('/teams', payload)
      this.list.unshift(data.data)
      return data.data
    },

    async update(id: string, payload: { name?: string; code?: string; is_active?: boolean }) {
      const { data } = await apiClient.patch<ApiEnvelope<Team>>(`/teams/${id}`, payload)
      const idx = this.list.findIndex((t) => t.id === id)
      if (idx !== -1) this.list[idx] = data.data
      return data.data
    },

    async remove(id: string) {
      await apiClient.delete(`/teams/${id}`)
      this.list = this.list.filter((t) => t.id !== id)
    },
  },
})
