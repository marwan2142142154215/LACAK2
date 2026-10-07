import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/** Tabel `device_heartbeats` — skema dimiliki Laravel (§34), AdonisJS menulis di sini
 * untuk jalur WS (jalur utama heartbeat, §6) — Laravel menulis baris yang sama untuk
 * jalur HTTPS fallback (§45). Kedua sisi konvergen ke tabel yang identik.
 */
export default class DeviceHeartbeat extends BaseModel {
  static table = 'device_heartbeats'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare batteryLevel: number | null

  @column()
  declare networkType: string | null

  @column()
  declare connectionState: string | null

  @column()
  declare appVersion: string | null

  @column()
  declare androidVersion: string | null

  @column.dateTime()
  declare recordedAt: DateTime

  @column.dateTime()
  declare receivedAt: DateTime

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime
}
