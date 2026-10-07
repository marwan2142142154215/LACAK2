<script setup lang="ts">
import { onMounted } from 'vue'
import { useDeviceStore } from '@/stores/devices'
import { Smartphone, Wifi, WifiOff, AlertTriangle, Lock, HelpCircle } from '@lucide/vue'

const devices = useDeviceStore()

onMounted(async () => {
  await devices.fetchOverview()
  await devices.fetchList({ per_page: 10 })
})

const cards = [
  { key: 'total', label: 'Total Device', icon: Smartphone, color: 'text-slate-700 bg-slate-100' },
  { key: 'online', label: 'Online', icon: Wifi, color: 'text-green-700 bg-green-100' },
  { key: 'degraded', label: 'Degraded', icon: AlertTriangle, color: 'text-amber-700 bg-amber-100' },
  { key: 'offline', label: 'Offline', icon: WifiOff, color: 'text-red-700 bg-red-100' },
  { key: 'locked', label: 'Terkunci', icon: Lock, color: 'text-blue-700 bg-blue-100' },
  { key: 'unknown', label: 'Tidak Diketahui', icon: HelpCircle, color: 'text-slate-500 bg-slate-100' },
] as const

const statusBadge: Record<string, string> = {
  ONLINE: 'bg-green-100 text-green-700',
  DEGRADED: 'bg-amber-100 text-amber-700',
  OFFLINE: 'bg-red-100 text-red-700',
  LOCKED: 'bg-blue-100 text-blue-700',
  UNKNOWN: 'bg-slate-100 text-slate-500',
}
</script>

<template>
  <div class="p-6 space-y-6">
    <h1 class="text-xl font-bold text-slate-900">Dashboard</h1>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
      <div
        v-for="card in cards"
        :key="card.key"
        class="bg-white rounded-xl border border-slate-200 p-4"
      >
        <div class="flex items-center gap-3">
          <div :class="['rounded-lg p-2', card.color]">
            <component :is="card.icon" :size="20" />
          </div>
          <div>
            <p class="text-2xl font-bold text-slate-900">{{ devices.overview?.[card.key] ?? '-' }}</p>
            <p class="text-xs text-slate-500">{{ card.label }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
      <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-900">Device Terbaru (heartbeat)</h2>
        <RouterLink :to="{ name: 'devices' }" class="text-xs font-medium text-blue-600 hover:underline">
          Lihat semua →
        </RouterLink>
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
            <th class="px-5 py-2 font-medium">Nama</th>
            <th class="px-5 py-2 font-medium">Status</th>
            <th class="px-5 py-2 font-medium">Site</th>
            <th class="px-5 py-2 font-medium">Tim</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="device in devices.list"
            :key="device.id"
            class="border-b border-slate-100 last:border-0 hover:bg-slate-50 cursor-pointer"
            @click="$router.push({ name: 'device-detail', params: { id: device.id } })"
          >
            <td class="px-5 py-3 font-medium text-slate-900">{{ device.name }}</td>
            <td class="px-5 py-3">
              <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-medium', statusBadge[device.status]]">
                {{ device.status }}
              </span>
            </td>
            <td class="px-5 py-3 text-slate-600">{{ device.site?.name ?? '-' }}</td>
            <td class="px-5 py-3 text-slate-600">{{ device.team?.name ?? '-' }}</td>
          </tr>
          <tr v-if="!devices.isLoading && devices.list.length === 0">
            <td colspan="4" class="px-5 py-8 text-center text-slate-400">Belum ada device terdaftar.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
