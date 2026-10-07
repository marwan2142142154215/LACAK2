// Tipe ini HARUS sinkron dengan docs/api.md & skema Laravel (docs/database.md).

export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
  errors?: Record<string, string[]> | unknown[]
}

export interface PaginatedEnvelope<T> {
  success: boolean
  message: string
  data: T[]
  meta: { total: number; current_page: number; last_page: number; per_page: number }
}

export interface User {
  id: string
  name: string
  email: string
  two_factor_enabled?: boolean
  roles: string[]
  permissions: string[]
}

export interface Site {
  id: string
  name: string
  code: string
  address: string | null
  is_active: boolean
  created_at: string
}

export interface Team {
  id: string
  site_id: string
  name: string
  code: string
  is_active: boolean
  site?: Site
}

export type DeviceStatus = 'ONLINE' | 'DEGRADED' | 'OFFLINE' | 'UNKNOWN' | 'LOCKED'

export interface Device {
  id: string
  site_id: string
  team_id: string
  name: string
  status: DeviceStatus
  is_managed: boolean
  android_api_level: number | null
  android_version: string | null
  app_version: string | null
  manufacturer: string | null
  model: string | null
  capability_report: Record<string, boolean> | null
  last_heartbeat_at: string | null
  is_active: boolean
  site?: Site
  team?: Team
}

export type CommandType = 'LOCK' | 'UNLOCK' | 'LOCATION_REQUEST' | 'CAMERA_REQUEST'
export type CommandStatus =
  | 'PENDING' | 'QUEUED' | 'SENT' | 'DELIVERED' | 'RECEIVED'
  | 'EXECUTING' | 'SUCCESS' | 'FAILED' | 'EXPIRED' | 'CANCELLED'

export interface DeviceCommand {
  id: string
  device_id: string
  command_type: CommandType
  payload: Record<string, unknown> | null
  status: CommandStatus
  failure_reason: string | null
  created_at: string
  sent_at: string | null
  completed_at: string | null
}

export interface DeviceLocation {
  id: string
  device_id: string
  latitude: number
  longitude: number
  accuracy: number | null
  source: 'GPS' | 'NETWORK' | 'FUSED' | 'LAST_KNOWN'
  recorded_at: string
}

export interface DeviceMedia {
  id: string
  device_id: string
  camera_facing: 'FRONT' | 'BACK'
  mime_type: string
  size_bytes: number
  captured_at: string
}
