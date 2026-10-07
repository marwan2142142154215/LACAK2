import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/**
 * Tabel `device_sessions` — skema dimiliki Laravel (§34), tapi AdonisJS yang MENULIS
 * ke sini karena koneksi WebSocket/HTTPS-poll terjadi di sisi AdonisJS (§6/§43). Baris
 * ini adalah audit trail definitif siapa yang pernah connect — BUKAN disimpan di Redis
 * sebagai source of truth (§42); Redis presence hanya cache cepat di atas tabel ini.
 */
export default class DeviceSession extends BaseModel {
  static table = 'device_sessions'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare sessionTokenHash: string

  @column()
  declare connectionType: 'WEBSOCKET' | 'HTTPS_POLL'

  @column()
  declare gatewayNode: string | null

  @column.dateTime()
  declare connectedAt: DateTime

  @column.dateTime()
  declare lastActivityAt: DateTime

  @column.dateTime()
  declare disconnectedAt: DateTime | null

  @column()
  declare disconnectReason: string | null

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime
}
