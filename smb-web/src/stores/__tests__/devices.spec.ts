import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/api/client', () => ({
  apiClient: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import { apiClient } from '@/api/client'
import { useDeviceStore } from '@/stores/devices'

describe('useDeviceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('fetchList populates list+meta on success and clears any previous error', async () => {
    const store = useDeviceStore()
    store.error = 'error lama'
    ;(apiClient.get as any).mockResolvedValue({
      data: { data: [{ id: 'd-1', name: 'Gudang A' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 15 } },
    })

    await store.fetchList({ search: 'gudang' })

    expect(store.list).toHaveLength(1)
    expect(store.list[0].name).toBe('Gudang A')
    expect(store.meta.total).toBe(1)
    expect(store.error).toBeNull()
    expect(store.isLoading).toBe(false)
    expect(apiClient.get).toHaveBeenCalledWith('/devices', { params: { search: 'gudang' } })
  })

  it('fetchList sets an honest error message on failure instead of silently keeping stale data as if nothing happened', async () => {
    const store = useDeviceStore()
    ;(apiClient.get as any).mockRejectedValue({ response: { data: { message: 'Sesi berakhir.' } } })

    await store.fetchList()

    expect(store.error).toBe('Sesi berakhir.')
    expect(store.isLoading).toBe(false)
  })

  it('lock() sends the command then re-fetches the device (never assumes success locally, §66)', async () => {
    const store = useDeviceStore()
    ;(apiClient.post as any).mockResolvedValue({ data: {} })
    ;(apiClient.get as any).mockResolvedValue({ data: { data: { id: 'd-1', status: 'LOCKED' } } })

    await store.lock('d-1')

    expect(apiClient.post).toHaveBeenCalledWith('/devices/d-1/lock')
    expect(apiClient.get).toHaveBeenCalledWith('/devices/d-1')
    expect(store.current?.status).toBe('LOCKED')
  })

  it('remove() filters the device out of the local list only after the server call succeeds', async () => {
    const store = useDeviceStore()
    store.list = [{ id: 'd-1' } as any, { id: 'd-2' } as any]
    ;(apiClient.delete as any).mockResolvedValue({})

    await store.remove('d-1')

    expect(store.list.map((d) => d.id)).toEqual(['d-2'])
  })

  it('fetchLatestLocation resolves to null (not an exception) when the device has no location yet', async () => {
    const store = useDeviceStore()
    ;(apiClient.get as any).mockRejectedValue({ response: { status: 404 } })

    await store.fetchLatestLocation('d-1')

    expect(store.latestLocation).toBeNull()
  })
})
