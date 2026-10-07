<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { LayoutDashboard, Smartphone, Building2, Users, LogOut, ShieldCheck } from '@lucide/vue'

const auth = useAuthStore()
const router = useRouter()

const navItems = [
  { to: { name: 'dashboard' }, label: 'Dashboard', icon: LayoutDashboard, permission: null },
  { to: { name: 'devices' }, label: 'Device', icon: Smartphone, permission: 'devices.view' },
  { to: { name: 'sites' }, label: 'Site', icon: Building2, permission: 'sites.manage' },
  { to: { name: 'teams' }, label: 'Tim', icon: Users, permission: 'teams.manage' },
]

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex bg-slate-50">
    <aside class="w-60 shrink-0 bg-slate-900 text-slate-200 flex flex-col">
      <div class="flex items-center gap-2 px-5 py-5 border-b border-slate-800">
        <ShieldCheck class="text-blue-400" :size="24" />
        <div>
          <p class="text-[11px] font-bold tracking-wider text-blue-400">SMB</p>
          <p class="text-sm font-semibold text-white">Device Management</p>
        </div>
      </div>
      <nav class="flex-1 px-3 py-4 space-y-1">
        <RouterLink
          v-for="item in navItems.filter((i) => !i.permission || auth.can(i.permission))"
          :key="item.label"
          :to="item.to"
          class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
          active-class="bg-blue-600 text-white hover:bg-blue-600"
        >
          <component :is="item.icon" :size="18" />
          {{ item.label }}
        </RouterLink>
      </nav>
      <div class="px-3 py-4 border-t border-slate-800">
        <div class="px-3 pb-2">
          <p class="text-sm font-medium text-white truncate">{{ auth.user?.name }}</p>
          <p class="text-xs text-slate-400 truncate">{{ auth.user?.roles.join(', ') }}</p>
        </div>
        <button
          class="w-full flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-300 hover:bg-slate-800 hover:text-white transition-colors"
          @click="onLogout"
        >
          <LogOut :size="18" />
          Keluar
        </button>
      </div>
    </aside>
    <main class="flex-1 min-w-0 overflow-y-auto">
      <RouterView />
    </main>
  </div>
</template>
