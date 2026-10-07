<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useTeamStore } from '@/stores/teams'
import { useSiteStore } from '@/stores/sites'
import type { Team } from '@/types'
import { Plus, Pencil, Trash2 } from '@lucide/vue'

const teams = useTeamStore()
const sites = useSiteStore()
const showForm = ref(false)
const editing = ref<Team | null>(null)
const error = ref<string | null>(null)
const form = reactive({ site_id: '', name: '', code: '' })

onMounted(async () => {
  await sites.fetchList({ per_page: 100 })
  await teams.fetchList()
})

function openCreate() {
  editing.value = null
  form.site_id = sites.list[0]?.id ?? ''
  form.name = ''
  form.code = ''
  error.value = null
  showForm.value = true
}

function openEdit(team: Team) {
  editing.value = team
  form.site_id = team.site_id
  form.name = team.name
  form.code = team.code
  error.value = null
  showForm.value = true
}

async function submit() {
  error.value = null
  try {
    if (editing.value) {
      await teams.update(editing.value.id, { name: form.name, code: form.code })
    } else {
      await teams.create({ site_id: form.site_id, name: form.name, code: form.code })
    }
    showForm.value = false
  } catch (err: any) {
    error.value = err.response?.data?.message ?? 'Gagal menyimpan tim.'
  }
}

async function remove(team: Team) {
  if (!confirm(`Hapus tim "${team.name}"?`)) return
  await teams.remove(team.id)
}

function siteName(siteId: string) {
  return sites.list.find((s) => s.id === siteId)?.name ?? '-'
}
</script>

<template>
  <div class="p-6 space-y-4 max-w-4xl">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-slate-900">Tim</h1>
      <button
        class="flex items-center gap-1.5 rounded-lg bg-blue-600 text-white text-sm font-medium px-3 py-2"
        :disabled="sites.list.length === 0"
        @click="openCreate"
      >
        <Plus :size="14" /> Tambah Tim
      </button>
    </div>

    <div v-if="showForm" class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      <h2 class="text-sm font-semibold text-slate-900">{{ editing ? 'Edit Tim' : 'Tim Baru' }}</h2>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Site</label>
          <select v-model="form.site_id" :disabled="!!editing" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option v-for="site in sites.list" :key="site.id" :value="site.id">{{ site.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Kode</label>
          <input v-model="form.code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-slate-600 mb-1">Nama</label>
          <input v-model="form.name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
      </div>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <div class="flex gap-2">
        <button class="rounded-lg bg-blue-600 text-white text-sm font-medium px-3 py-2" @click="submit">Simpan</button>
        <button class="rounded-lg border border-slate-300 text-sm font-medium px-3 py-2" @click="showForm = false">Batal</button>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
            <th class="px-5 py-2 font-medium">Nama</th>
            <th class="px-5 py-2 font-medium">Kode</th>
            <th class="px-5 py-2 font-medium">Site</th>
            <th class="px-5 py-2 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="team in teams.list" :key="team.id" class="border-b border-slate-100 last:border-0">
            <td class="px-5 py-3 font-medium text-slate-900">{{ team.name }}</td>
            <td class="px-5 py-3 text-slate-600">{{ team.code }}</td>
            <td class="px-5 py-3 text-slate-600">{{ team.site?.name ?? siteName(team.site_id) }}</td>
            <td class="px-5 py-3 flex gap-2 justify-end">
              <button class="text-slate-400 hover:text-slate-700" @click="openEdit(team)"><Pencil :size="15" /></button>
              <button class="text-red-500 hover:text-red-700" @click="remove(team)"><Trash2 :size="15" /></button>
            </td>
          </tr>
          <tr v-if="teams.list.length === 0">
            <td colspan="4" class="px-5 py-8 text-center text-slate-400">Belum ada tim.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
