import { DateTime } from 'luxon'

/**
 * §6: HARUS identik secara nilai dengan App\Services\DeviceStatusResolver.php di
 * smb-api — dua implementasi independen (PHP & TS) karena kedua service menulis
 * status, tapi thresholdnya WAJIB sama supaya tidak ada "flapping" status gara-gara
 * AdonisJS dan Laravel punya definisi ONLINE yang beda.
 */
export const ONLINE_THRESHOLD_SECONDS = 90
export const DEGRADED_THRESHOLD_SECONDS = 300

export function resolveDeviceStatus(lastHeartbeatAt: DateTime | null): 'UNKNOWN' | 'ONLINE' | 'DEGRADED' | 'OFFLINE' {
  if (!lastHeartbeatAt) return 'UNKNOWN'

  const ageSeconds = DateTime.now().diff(lastHeartbeatAt, 'seconds').seconds

  if (ageSeconds <= ONLINE_THRESHOLD_SECONDS) return 'ONLINE'
  if (ageSeconds <= DEGRADED_THRESHOLD_SECONDS) return 'DEGRADED'
  return 'OFFLINE'
}
