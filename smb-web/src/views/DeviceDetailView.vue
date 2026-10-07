<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDeviceStore } from '@/stores/devices'
import { useAuthStore } from '@/stores/auth'
import dayjs from '@/lib/dayjs'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { Lock, Unlock, MapPin, Pencil, Trash2 } from '@lucide/vue'

const route = useRoute()
const router = useRouter()
const devices = useDeviceStore()
const auth = useAuthStore()

const deviceId = route.params.id as string
const actionError = ref<string | null>(null)
const actionLoading = ref<string | null>(null)
const isEditingName = ref(false)
const nameDraft = ref('')
const mapEl = ref<HTMLDivElement | null>(null)
let map: L.Map | null = null
let marker: L.Marker | null = null

const statusBadge: Record<string, string> = {
  ONLINE: 'bg-green-100 text-green-700',
  DEGRADED: 'bg-amber-100 text-amber-700',
  OFFLINE: 'bg-red-100 text-red-700',
  LOCKED: 'bg-blue-100 text-blue-700',
  UNKNOWN: 'bg-slate-100 text-slate-500',
}

async function refresh() {
  await devices.fetchOne(deviceId)
  await devices.fetchLatestLocation(deviceId)
  await devices.fetchCommands(deviceId)
}

function renderMap() {
  if (!mapEl.value) return
  const loc = devices.latestLocation
  const lat = loc ? loc.latitude : -6.2
  const lng = loc ? loc.longitude : 106.8167

  if (!map) {
    map = L.map(mapEl.value).setView([lat, lng], loc ? 16 : 10)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map)
  } else {
    map.setView([lat, lng], loc ? 16 : map.getZoom())
  }

  if (loc) {
    if (marker) marker.setLatLng([lat, lng])
    else marker = L.marker([lat, lng]).addTo(map)
    marker.bindPopup(`Akurasi ±${loc.accuracy ?? '?'}m — ${dayjs(loc.recorded_at).fromNow()}`)
  }
}

watch(() => devices.latestLocation, async () => {
  await nextTick()
  renderMap()
})

onMounted(async () => {
  await refresh()
  await nextTick()
  renderMap()
})

onBeforeUnmount(() => {
  map?.remove()
})

async function run(action: string, fn: () => Promise<void>) {
  actionLoading.value = action
  actionError.value = null
  try {
    await fn()
  } catch (err: any) {
    actionError.value = err.response?.data?.message ?? 'Aksi gagal dijalankan.'
  } finally {
    actionLoading.value = null
  }
}

function startEditName() {
  nameDraft.value = devices.current?.name ?? ''
  isEditingName.value = true
}

async function saveName() {
  await run('rename', async () => {
    await devices.rename(deviceId, nameDraft.value)
    isEditingName.value = false
  })
}

async function onDelete() {
  if (!confirm(`Hapus device "${devices.current?.name}"? Device bisa dipulihkan dari database jika perlu.`)) return
  await run('delete', async () => {
    await devices.remove(deviceId)
    router.push({ name: 'devices' })
  })
}
</script>

<template>
  <div v-if="devices.current" class="p-6 space-y-6 max-w-5xl">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <template v-if="!isEditingName">
          <h1 class="text-xl font-bold text-slate-900">{{ devices.current.name }}</h1>
          <button v-if="auth.can('devices.update')" class="text-slate-400 hover:text-slate-700" @click="startEditName">
            <Pencil :size="16" />
          </button>
        </template>
        <template v-else>
          <input
            v-model="nameDraft"
            class="rounded-lg border border-slate-300 px-3 py-1.5 text-lg font-bold"
            @keyup.enter="saveName"
          />
          <button class="text-sm text-blue-600 font-medium" :disabled="actionLoading === 'rename'" @click="saveName">Simpan</button>
          <button class="text-sm text-slate-500" @click="isEditingName = false">Batal</button>
        </template>
        <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-medium', statusBadge[devices.current.status]]">
          {{ devices.current.status }}
        </span>
      </div>
      <button
        v-if="auth.can('devices.delete')"
        class="flex items-center gap-1.5 text-sm text-red-600 font-medium hover:underline"
        @click="onDelete"
      >
        <Trash2 :size="14" /> Hapus Device
      </button>
    </div>

    <p v-if="actionError" class="text-sm text-red-600">{{ actionError }}</p>

    <div class="grid md:grid-cols-2 gap-4">
      <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
        <h2 class="text-sm font-semibold text-slate-900">Identitas</h2>
        <dl class="text-sm grid grid-cols-2 gap-y-2">
          <dt class="text-slate-500">Site</dt>
          <dd class="text-slate-900">{{ devices.current.site?.name ?? '-' }}</dd>
          <dt class="text-slate-500">Tim</dt>
          <dd class="text-slate-900">{{ devices.current.team?.name ?? '-' }}</dd>
          <dt class="text-slate-500">Manufacturer</dt>
          <dd class="text-slate-900">{{ devices.current.manufacturer ?? '-' }}</dd>
          <dt class="text-slate-500">Model</dt>
          <dd class="text-slate-900">{{ devices.current.model ?? '-' }}</dd>
          <dt class="text-slate-500">Android</dt>
          <dd class="text-slate-900">{{ devices.current.android_version ?? '-' }} (API {{ devices.current.android_api_level ?? '-' }})</dd>
          <dt class="text-slate-500">App Version</dt>
          <dd class="text-slate-900">{{ devices.current.app_version ?? '-' }}</dd>
          <dt class="text-slate-500">Heartbeat Terakhir</dt>
          <dd class="text-slate-900">
            {{ devices.current.last_heartbeat_at ? dayjs(devices.current.last_heartbeat_at).fromNow() : 'Belum pernah' }}
          </dd>
        </dl>
      </div>

      <div class="bg-white rounded-xl border border-slate-200 p-5 space-y-4">
        <h2 class="text-sm font-semibold text-slate-900">Kontrol Device</h2>
        <div class="flex flex-wrap gap-2">
          <button
            v-if="auth.can('devices.lock') && devices.current.status !== 'LOCKED'"
            class="flex items-center gap-1.5 rounded-lg bg-slate-900 text-white text-sm font-medium px-3 py-2 disabled:opacity-50"
            :disabled="actionLoading === 'lock'"
            @click="run('lock', () => devices.lock(deviceId))"
          >
            <Lock :size="14" /> Kunci Device
          </button>
          <button
            v-if="auth.can('devices.unlock') && devices.current.status === 'LOCKED'"
            class="flex items-center gap-1.5 rounded-lg bg-blue-600 text-white text-sm font-medium px-3 py-2 disabled:opacity-50"
            :disabled="actionLoading === 'unlock'"
            @click="run('unlock', () => devices.unlock(deviceId))"
          >
            <Unlock :size="14" /> Buka Kunci
          </button>
          <button
            v-if="auth.can('devices.location')"
            class="flex items-center gap-1.5 rounded-lg border border-slate-300 text-sm font-medium px-3 py-2 disabled:opacity-50"
            :disabled="actionLoading === 'location'"
            @click="run('location', async () => { await devices.requestLocation(deviceId); await devices.fetchLatestLocation(deviceId) })"
          >
            <MapPin :size="14" /> Minta Lokasi
          </button>
        </div>
        <p class="text-xs text-slate-400">
          Permintaan lokasi bersifat async — device merespons via WebSocket lalu menulis lokasi baru (bisa butuh beberapa detik).
        </p>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
      <h2 class="text-sm font-semibold text-slate-900 mb-3">Lokasi Terakhir</h2>
      <div ref="mapEl" class="h-72 rounded-lg overflow-hidden border border-slate-200"></div>
      <p v-if="!devices.latestLocation" class="text-xs text-slate-400 mt-2">Belum ada data lokasi untuk device ini.</p>
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
      <div class="px-5 py-4 border-b border-slate-200">
        <h2 class="text-sm font-semibold text-slate-900">Riwayat Command</h2>
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
            <th class="px-5 py-2 font-medium">Tipe</th>
            <th class="px-5 py-2 font-medium">Status</th>
            <th class="px-5 py-2 font-medium">Dibuat</th>
            <th class="px-5 py-2 font-medium">Selesai</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="cmd in devices.commands" :key="cmd.id" class="border-b border-slate-100 last:border-0">
            <td class="px-5 py-3 font-medium text-slate-900">{{ cmd.command_type }}</td>
            <td class="px-5 py-3 text-slate-600">{{ cmd.status }}</td>
            <td class="px-5 py-3 text-slate-600">{{ dayjs(cmd.created_at).fromNow() }}</td>
            <td class="px-5 py-3 text-slate-600">{{ cmd.completed_at ? dayjs(cmd.completed_at).fromNow() : '-' }}</td>
          </tr>
          <tr v-if="devices.commands.length === 0">
            <td colspan="4" class="px-5 py-8 text-center text-slate-400">Belum ada command untuk device ini.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <div v-else-if="devices.error" class="p-6 text-sm text-red-600">{{ devices.error }}</div>
  <div v-else class="p-6 text-sm text-slate-400">Memuat…</div>
</template>
