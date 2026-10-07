import { defineStore } from 'pinia'
import { apiClient } from '@/api/client'
import type { PaginatedEnvelope } from '@/types'

export interface UserSummary {
  id: string
  name: string
  email: string
  roles: string[]
}

export const useUserStore = defineStore('users', {
  state: () => ({
    list: [] as UserSummary[],
    isLoading: false,
  }),

  actions: {
    async fetchList() {
      this.isLoading = true
      try {
        const { data } = await apiClient.get<PaginatedEnvelope<UserSummary>>('/users', { params: { per_page: 100 } })
        this.list = data.data
      } finally {
        this.isLoading = false
      }
    },
  },
})
