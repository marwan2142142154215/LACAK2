<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from 'vee-validate'
import * as yup from 'yup'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ShieldCheck } from '@lucide/vue'

const auth = useAuthStore()
const router = useRouter()
const step = ref<'credentials' | 'two-factor'>('credentials')

const credentialsSchema = yup.object({
  email: yup.string().email('Format email tidak valid.').required('Email wajib diisi.'),
  password: yup.string().required('Password wajib diisi.'),
})

const { defineField, handleSubmit, errors } = useForm({
  validationSchema: credentialsSchema,
})
const [email, emailAttrs] = defineField('email')
const [password, passwordAttrs] = defineField('password')

const code = ref('')
const submitting = ref(false)

const onSubmitCredentials = handleSubmit(async (values) => {
  submitting.value = true
  try {
    const result = await auth.login(values.email, values.password)
    if (result === 'needs_2fa') {
      step.value = 'two-factor'
    } else {
      await auth.fetchMe()
      router.push({ name: 'dashboard' })
    }
  } catch {
    // auth.error sudah diisi, ditampilkan di template
  } finally {
    submitting.value = false
  }
})

async function onSubmitTwoFactor() {
  submitting.value = true
  try {
    await auth.verifyTwoFactor(code.value)
    await auth.fetchMe()
    router.push({ name: 'dashboard' })
  } catch {
    // auth.error sudah diisi
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-slate-100 px-4">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
      <div class="flex items-center gap-2 mb-6">
        <ShieldCheck class="text-blue-600" :size="28" />
        <div>
          <p class="text-xs font-bold tracking-wider text-blue-600">SMB</p>
          <h1 class="text-lg font-bold text-slate-900">Masuk ke Dashboard</h1>
        </div>
      </div>

      <form v-if="step === 'credentials'" class="space-y-4" @submit="onSubmitCredentials">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input
            v-model="email"
            v-bind="emailAttrs"
            type="email"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <p v-if="errors.email" class="text-xs text-red-600 mt-1">{{ errors.email }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input
            v-model="password"
            v-bind="passwordAttrs"
            type="password"
            class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <p v-if="errors.password" class="text-xs text-red-600 mt-1">{{ errors.password }}</p>
        </div>
        <p v-if="auth.error" class="text-sm text-red-600">{{ auth.error }}</p>
        <button
          type="submit"
          :disabled="submitting"
          class="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          {{ submitting ? 'Memproses…' : 'Masuk' }}
        </button>
      </form>

      <form v-else class="space-y-4" @submit.prevent="onSubmitTwoFactor">
        <p class="text-sm text-slate-600">Masukkan kode 6 digit dari aplikasi autentikator Anda.</p>
        <input
          v-model="code"
          type="text"
          inputmode="numeric"
          maxlength="6"
          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-lg tracking-widest font-mono focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
        <p v-if="auth.error" class="text-sm text-red-600">{{ auth.error }}</p>
        <button
          type="submit"
          :disabled="submitting"
          class="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-medium hover:bg-blue-700 disabled:opacity-50"
        >
          {{ submitting ? 'Memverifikasi…' : 'Verifikasi' }}
        </button>
      </form>
    </div>
  </div>
</template>
