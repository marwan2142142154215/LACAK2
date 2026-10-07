import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      component: () => import('@/components/AppLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
        {
          path: 'devices',
          name: 'devices',
          component: () => import('@/views/DevicesView.vue'),
          meta: { permission: 'devices.view' },
        },
        {
          path: 'devices/:id',
          name: 'device-detail',
          component: () => import('@/views/DeviceDetailView.vue'),
          meta: { permission: 'devices.view' },
        },
        {
          path: 'sites',
          name: 'sites',
          component: () => import('@/views/SitesView.vue'),
          meta: { permission: 'sites.manage' },
        },
        {
          path: 'teams',
          name: 'teams',
          component: () => import('@/views/TeamsView.vue'),
          meta: { permission: 'teams.manage' },
        },
        {
          path: 'telegram',
          name: 'telegram',
          component: () => import('@/views/TelegramView.vue'),
          meta: { permission: 'telegram.manage' },
        },
      ],
    },
  ],
})

// §14: otorisasi WAJIB ditegakkan server (sudah — tiap endpoint API cek permission
// sendiri). Guard di sini HANYA UX (sembunyikan menu/redirect lebih awal) — bukan
// satu-satunya lapisan keamanan (§14 "jangan hanya menyembunyikan menu").
router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.public) {
    if (auth.isAuthenticated && to.name === 'login') return { name: 'dashboard' }
    return true
  }

  if (!auth.isAuthenticated) return { name: 'login' }

  // Token ada di localStorage (dari sesi sebelumnya) tapi profil belum dimuat
  // ulang setelah refresh halaman — ambil sekali supaya auth.can() punya data.
  if (!auth.user) {
    try {
      await auth.fetchMe()
    } catch {
      return { name: 'login' }
    }
  }

  const requiredPermission = to.meta.permission as string | undefined
  if (requiredPermission && !auth.can(requiredPermission)) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
