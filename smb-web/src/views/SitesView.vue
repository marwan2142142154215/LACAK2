<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useSiteStore } from '@/stores/sites'
import type { Site } from '@/types'
import { Plus, Pencil, Trash2 } from '@lucide/vue'

const sites = useSiteStore()
const showForm = ref(false)
const editing = ref<Site | null>(null)
const error = ref<string | null>(null)
const form = reactive({ name: '', code: '', address: '' })

onMounted(() => sites.fetchList())

function openCreate() {
  editing.value = null
  form.name = ''
  form.code = ''
  form.address = ''
  error.value = null
  showForm.value = true
}

function openEdit(site: Site) {
  editing.value = site
  form.name = site.name
  form.code = site.code
  form.address = site.address ?? ''
  error.value = null
  showForm.value = true
}

async function submit() {
  error.value = null
  try {
    if (editing.value) {
      await sites.update(editing.value.id, { name: form.name, code: form.code, address: form.address })
    } else {
      await sites.create({ name: form.name, code: form.code, address: form.address })
    }
    showForm.value = false
  } catch (err: any) {
    error.value = err.response?.data?.message ?? 'Gagal menyimpan site.'
  }
}

async function remove(site: Site) {
  if (!confirm(`Hapus site "${site.name}"?`)) return
  await sites.remove(site.id)
}
</script>

<template>
  <div class="p-6 space-y-4 max-w-4xl">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-slate-900">Site</h1>
      <button
        class="flex items-center gap-1.5 rounded-lg bg-blue-600 text-white text-sm font-medium px-3 py-2"
        @click="openCreate"
      >
        <Plus :size="14" /> Tambah Site
      </button>
    </div>

    <div v-if="showForm" class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      <h2 class="text-sm font-semibold text-slate-900">{{ editing ? 'Edit Site' : 'Site Baru' }}</h2>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Nama</label>
          <input v-model="form.name" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Kode</label>
          <input v-model="form.code" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
        <div class="col-span-2">
          <label class="block text-xs font-medium text-slate-600 mb-1">Alamat</label>
          <input v-model="form.address" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
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
            <th class="px-5 py-2 font-medium">Alamat</th>
            <th class="px-5 py-2 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="site in sites.list" :key="site.id" class="border-b border-slate-100 last:border-0">
            <td class="px-5 py-3 font-medium text-slate-900">{{ site.name }}</td>
            <td class="px-5 py-3 text-slate-600">{{ site.code }}</td>
            <td class="px-5 py-3 text-slate-600">{{ site.address ?? '-' }}</td>
            <td class="px-5 py-3 flex gap-2 justify-end">
              <button class="text-slate-400 hover:text-slate-700" @click="openEdit(site)"><Pencil :size="15" /></button>
              <button class="text-red-500 hover:text-red-700" @click="remove(site)"><Trash2 :size="15" /></button>
            </td>
          </tr>
          <tr v-if="sites.list.length === 0">
            <td colspan="4" class="px-5 py-8 text-center text-slate-400">Belum ada site.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
