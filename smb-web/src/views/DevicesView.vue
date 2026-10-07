<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useDeviceStore } from '@/stores/devices'
import { useSiteStore } from '@/stores/sites'
import dayjs from '@/lib/dayjs'

const devices = useDeviceStore()
const sites = useSiteStore()
const router = useRouter()

const search = ref('')
const statusFilter = ref('')
const siteFilter = ref('')
const page = ref(1)

const statusOptions = ['', 'ONLINE', 'DEGRADED', 'OFFLINE', 'LOCKED', 'UNKNOWN']

const statusBadge: Record<string, string> = {
  ONLINE: 'bg-green-100 text-green-700',
  DEGRADED: 'bg-amber-100 text-amber-700',
  OFFLINE: 'bg-red-100 text-red-700',
  LOCKED: 'bg-blue-100 text-blue-700',
  UNKNOWN: 'bg-slate-100 text-slate-500',
}

function load() {
  devices.fetchList({
    search: search.value || undefined,
    status: statusFilter.value || undefined,
    site_id: siteFilter.value || undefined,
    page: page.value,
    per_page: 15,
  })
}

let searchTimer: ReturnType<typeof setTimeout>
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})
watch([statusFilter, siteFilter], () => {
  page.value = 1
  load()
})
watch(page, load)

onMounted(() => {
  sites.fetchList({ per_page: 100 })
  load()
})
</script>

<template>
  <div class="p-6 space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-slate-900">Device</h1>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap gap-3">
      <input
        v-model="search"
        type="text"
        placeholder="Cari nama device…"
        class="flex-1 min-w-[180px] rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
      <select
        v-model="statusFilter"
        class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option v-for="s in statusOptions" :key="s" :value="s">{{ s || 'Semua Status' }}</option>
      </select>
      <select
        v-model="siteFilter"
        class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option value="">Semua Site</option>
        <option v-for="site in sites.list" :key="site.id" :value="site.id">{{ site.name }}</option>
      </select>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
            <th class="px-5 py-2 font-medium">Nama</th>
            <th class="px-5 py-2 font-medium">Status</th>
            <th class="px-5 py-2 font-medium">Site</th>
            <th class="px-5 py-2 font-medium">Tim</th>
            <th class="px-5 py-2 font-medium">Android</th>
            <th class="px-5 py-2 font-medium">Heartbeat Terakhir</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="device in devices.list"
            :key="device.id"
            class="border-b border-slate-100 last:border-0 hover:bg-slate-50 cursor-pointer"
            @click="router.push({ name: 'device-detail', params: { id: device.id } })"
          >
            <td class="px-5 py-3 font-medium text-slate-900">{{ device.name }}</td>
            <td class="px-5 py-3">
              <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-medium', statusBadge[device.status]]">
                {{ device.status }}
              </span>
            </td>
            <td class="px-5 py-3 text-slate-600">{{ device.site?.name ?? '-' }}</td>
            <td class="px-5 py-3 text-slate-600">{{ device.team?.name ?? '-' }}</td>
            <td class="px-5 py-3 text-slate-600">{{ device.android_version ?? '-' }}</td>
            <td class="px-5 py-3 text-slate-600">
              {{ device.last_heartbeat_at ? dayjs(device.last_heartbeat_at).fromNow() : 'Belum pernah' }}
            </td>
          </tr>
          <tr v-if="!devices.isLoading && devices.list.length === 0">
            <td colspan="6" class="px-5 py-8 text-center text-slate-400">
              Tidak ada device yang cocok dengan filter.
            </td>
          </tr>
        </tbody>
      </table>
      <div class="px-5 py-3 border-t border-slate-200 flex items-center justify-between text-xs text-slate-500">
        <span>Halaman {{ devices.meta.current_page }} dari {{ devices.meta.last_page }} ({{ devices.meta.total }} device)</span>
        <div class="flex gap-2">
          <button
            class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-40"
            :disabled="devices.meta.current_page <= 1"
            @click="page = devices.meta.current_page - 1"
          >
            Sebelumnya
          </button>
          <button
            class="rounded-lg border border-slate-300 px-3 py-1.5 disabled:opacity-40"
            :disabled="devices.meta.current_page >= devices.meta.last_page"
            @click="page = devices.meta.current_page + 1"
          >
            Selanjutnya
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
