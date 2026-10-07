import { defineStore } from 'pinia'
import { apiClient } from '@/api/client'
import type { ApiEnvelope, PaginatedEnvelope, Site } from '@/types'

export const useSiteStore = defineStore('sites', {
  state: () => ({
    list: [] as Site[],
    meta: { total: 0, current_page: 1, last_page: 1, per_page: 15 },
    isLoading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchList(params: { search?: string; page?: number; per_page?: number } = {}) {
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.get<PaginatedEnvelope<Site>>('/sites', { params })
        this.list = data.data
        this.meta = data.meta
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Gagal memuat daftar site.'
      } finally {
        this.isLoading = false
      }
    },

    async create(payload: { name: string; code: string; address?: string }) {
      const { data } = await apiClient.post<ApiEnvelope<Site>>('/sites', payload)
      this.list.unshift(data.data)
      return data.data
    },

    async update(id: string, payload: { name?: string; code?: string; address?: string; is_active?: boolean }) {
      const { data } = await apiClient.patch<ApiEnvelope<Site>>(`/sites/${id}`, payload)
      const idx = this.list.findIndex((s) => s.id === id)
      if (idx !== -1) this.list[idx] = data.data
      return data.data
    },

    async remove(id: string) {
      await apiClient.delete(`/sites/${id}`)
      this.list = this.list.filter((s) => s.id !== id)
    },
  },
})
