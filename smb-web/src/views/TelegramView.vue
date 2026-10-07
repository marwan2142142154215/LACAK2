<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useTelegramStore, type TelegramAccount } from '@/stores/telegram'
import { useUserStore } from '@/stores/users'
import dayjs from '@/lib/dayjs'
import { Check, Ban } from '@lucide/vue'

const telegram = useTelegramStore()
const users = useUserStore()

const statusBadge: Record<string, string> = {
  PENDING: 'bg-amber-100 text-amber-700',
  APPROVED: 'bg-green-100 text-green-700',
  REVOKED: 'bg-red-100 text-red-700',
}

const approving = ref<TelegramAccount | null>(null)
const error = ref<string | null>(null)
const form = reactive({ user_id: '', step_up_required: true })

onMounted(async () => {
  await users.fetchList()
  await telegram.fetchList()
})

function openApprove(account: TelegramAccount) {
  approving.value = account
  form.user_id = account.user_id ?? ''
  form.step_up_required = account.step_up_required
  error.value = null
}

async function submitApprove() {
  if (!approving.value) return
  error.value = null
  try {
    await telegram.approve(approving.value.id, form.user_id, form.step_up_required)
    approving.value = null
  } catch (err: any) {
    error.value = err.response?.data?.message ?? 'Gagal menyetujui akun.'
  }
}

async function revoke(account: TelegramAccount) {
  if (!confirm(`Cabut akses Telegram untuk @${account.telegram_username ?? account.telegram_id}?`)) return
  await telegram.revoke(account.id)
}
</script>

<template>
  <div class="p-6 space-y-4 max-w-4xl">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Akun Telegram</h1>
      <p class="text-sm text-slate-500">
        Akun yang mengirim /start ke bot muncul di sini sebagai PENDING. Setujui untuk menautkan ke
        user sistem — tanpa persetujuan, akun tidak bisa menjalankan perintah device apa pun.
      </p>
    </div>

    <div v-if="approving" class="bg-white rounded-xl border border-slate-200 p-5 space-y-3">
      <h2 class="text-sm font-semibold text-slate-900">
        Setujui @{{ approving.telegram_username ?? approving.telegram_id }}
      </h2>
      <div>
        <label class="block text-xs font-medium text-slate-600 mb-1">Tautkan ke User</label>
        <select v-model="form.user_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="" disabled>Pilih user…</option>
          <option v-for="u in users.list" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }}) — {{ u.roles.join(', ') }}</option>
        </select>
      </div>
      <label class="flex items-center gap-2 text-sm text-slate-700">
        <input v-model="form.step_up_required" type="checkbox" class="rounded border-slate-300" />
        Wajib konfirmasi (/confirm) untuk perintah sensitif (lock/unlock/lokasi)
      </label>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <div class="flex gap-2">
        <button class="rounded-lg bg-blue-600 text-white text-sm font-medium px-3 py-2" :disabled="!form.user_id" @click="submitApprove">
          Setujui
        </button>
        <button class="rounded-lg border border-slate-300 text-sm font-medium px-3 py-2" @click="approving = null">Batal</button>
      </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-slate-500 border-b border-slate-200">
            <th class="px-5 py-2 font-medium">Telegram</th>
            <th class="px-5 py-2 font-medium">Status</th>
            <th class="px-5 py-2 font-medium">Tertaut ke</th>
            <th class="px-5 py-2 font-medium">Diminta</th>
            <th class="px-5 py-2 font-medium"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="account in telegram.list" :key="account.id" class="border-b border-slate-100 last:border-0">
            <td class="px-5 py-3 font-medium text-slate-900">
              @{{ account.telegram_username ?? '-' }}
              <span class="text-slate-400 font-normal">#{{ account.telegram_id }}</span>
            </td>
            <td class="px-5 py-3">
              <span :class="['inline-block rounded-full px-2 py-0.5 text-xs font-medium', statusBadge[account.status]]">
                {{ account.status }}
              </span>
            </td>
            <td class="px-5 py-3 text-slate-600">{{ account.user?.name ?? '-' }}</td>
            <td class="px-5 py-3 text-slate-600">{{ dayjs(account.created_at).fromNow() }}</td>
            <td class="px-5 py-3 flex gap-2 justify-end">
              <button
                v-if="account.status !== 'APPROVED'"
                class="flex items-center gap-1 text-green-600 hover:underline text-xs font-medium"
                @click="openApprove(account)"
              >
                <Check :size="14" /> Setujui
              </button>
              <button
                v-if="account.status !== 'REVOKED'"
                class="flex items-center gap-1 text-red-600 hover:underline text-xs font-medium"
                @click="revoke(account)"
              >
                <Ban :size="14" /> Cabut
              </button>
            </td>
          </tr>
          <tr v-if="telegram.list.length === 0">
            <td colspan="5" class="px-5 py-8 text-center text-slate-400">Belum ada akun Telegram yang terdaftar.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
