import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/** Tabel `site_network_policies` — skema dimiliki Laravel (§34/§98). AdonisJS hanya
 * membaca (evaluasi saat heartbeat WS, §97-102) — CRUD tetap lewat smb-api/Dashboard. */
export default class SiteNetworkPolicy extends BaseModel {
  static table = 'site_network_policies'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare siteId: string

  @column()
  declare networkType: 'IP' | 'CIDR'

  @column()
  declare value: string

  @column()
  declare isActive: boolean

  @column.dateTime()
  declare deletedAt: DateTime | null

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime
}
