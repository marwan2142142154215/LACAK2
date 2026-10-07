import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/**
 * Model untuk tabel `devices` — SKEMA DIMILIKI LARAVEL (smb-api/database/migrations).
 * AdonisJS membaca/menulis ke tabel yang sama via model ini; field yang didaftarkan di
 * sini hanya yang AdonisJS benar-benar perlu (akan ditambah progresif per phase, bukan
 * mendaftarkan semua kolom sekaligus secara spekulatif).
 */
export default class Device extends BaseModel {
  static table = 'devices'

  // UUID, bukan auto-increment (§18) — Lucid tidak boleh mencoba generate/assume integer id.
  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare siteId: string

  @column()
  declare teamId: string

  @column()
  declare status: 'ONLINE' | 'DEGRADED' | 'OFFLINE' | 'UNKNOWN' | 'LOCKED'

  @column()
  declare isManaged: boolean

  @column.dateTime()
  declare lastHeartbeatAt: DateTime | null

  @column()
  declare isActive: boolean

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime
}
