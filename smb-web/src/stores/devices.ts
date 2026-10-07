import { defineStore } from 'pinia'
import { apiClient } from '@/api/client'
import type { ApiEnvelope, PaginatedEnvelope, Device, DeviceCommand, DeviceLocation } from '@/types'

export interface DeviceOverview {
  total: number
  online: number
  degraded: number
  offline: number
  locked: number
  unknown: number
}

export interface DeviceFilters {
  site_id?: string
  team_id?: string
  status?: string
  search?: string
  page?: number
  per_page?: number
}

export const useDeviceStore = defineStore('devices', {
  state: () => ({
    list: [] as Device[],
    meta: { total: 0, current_page: 1, last_page: 1, per_page: 15 },
    overview: null as DeviceOverview | null,
    current: null as Device | null,
    commands: [] as DeviceCommand[],
    latestLocation: null as DeviceLocation | null,
    isLoading: false,
    error: null as string | null,
  }),

  actions: {
    async fetchOverview() {
      const { data } = await apiClient.get<ApiEnvelope<DeviceOverview>>('/devices/overview')
      this.overview = data.data
    },

    async fetchList(filters: DeviceFilters = {}) {
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.get<PaginatedEnvelope<Device>>('/devices', { params: filters })
        this.list = data.data
        this.meta = data.meta
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Gagal memuat daftar device.'
      } finally {
        this.isLoading = false
      }
    },

    async fetchOne(id: string) {
      this.isLoading = true
      this.error = null
      try {
        const { data } = await apiClient.get<ApiEnvelope<Device>>(`/devices/${id}`)
        this.current = data.data
      } catch (err: any) {
        this.error = err.response?.data?.message ?? 'Gagal memuat detail device.'
      } finally {
        this.isLoading = false
      }
    },

    async rename(id: string, name: string) {
      const { data } = await apiClient.patch<ApiEnvelope<Device>>(`/devices/${id}`, { name })
      this.current = data.data
      return data.data
    },

    async remove(id: string) {
      await apiClient.delete(`/devices/${id}`)
      this.list = this.list.filter((d) => d.id !== id)
    },

    async lock(id: string) {
      await apiClient.post(`/devices/${id}/lock`)
      await this.fetchOne(id)
    },

    async unlock(id: string) {
      await apiClient.post(`/devices/${id}/unlock`)
      await this.fetchOne(id)
    },

    async requestLocation(id: string) {
      await apiClient.post(`/devices/${id}/location/request`)
    },

    async fetchLatestLocation(id: string) {
      try {
        const { data } = await apiClient.get<ApiEnvelope<DeviceLocation>>(`/devices/${id}/locations/latest`)
        this.latestLocation = data.data
      } catch {
        this.latestLocation = null
      }
    },

    async fetchCommands(id: string) {
      const { data } = await apiClient.get<PaginatedEnvelope<DeviceCommand>>(`/devices/${id}/commands`)
      this.commands = data.data
    },
  },
})
