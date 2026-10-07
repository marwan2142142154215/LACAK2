import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/** Tabel `device_network_violations` — skema dimiliki Laravel (§34/§103-106). */
export default class DeviceNetworkViolation extends BaseModel {
  static table = 'device_network_violations'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare siteId: string

  @column()
  declare observedIp: string | null

  @column()
  declare policyStatus: 'BLOCKED' | 'UNKNOWN'

  @column()
  declare severity: 'INFO' | 'WARNING' | 'HIGH' | 'CRITICAL'

  @column.dateTime()
  declare firstSeenAt: DateTime

  @column.dateTime()
  declare lastSeenAt: DateTime

  @column.dateTime()
  declare alertSentAt: DateTime | null

  @column.dateTime()
  declare resolvedAt: DateTime | null
}
