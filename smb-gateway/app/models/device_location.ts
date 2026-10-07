import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/** Tabel `device_locations` — skema dimiliki Laravel (§34). Ditulis AdonisJS saat
 * command LOCATION_REQUEST ber-ack SUCCESS dengan payload result (§25/§26). */
export default class DeviceLocation extends BaseModel {
  static table = 'device_locations'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare latitude: number

  @column()
  declare longitude: number

  @column()
  declare accuracy: number | null

  @column()
  declare source: 'GPS' | 'NETWORK' | 'FUSED' | 'LAST_KNOWN'

  @column.dateTime()
  declare recordedAt: DateTime

  @column.dateTime()
  declare receivedAt: DateTime

  @column()
  declare requestedByCommandId: string | null

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime
}
