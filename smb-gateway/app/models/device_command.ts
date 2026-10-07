import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

export const TERMINAL_STATUSES = ['SUCCESS', 'FAILED', 'EXPIRED', 'CANCELLED'] as const
export type CommandStatus =
  | 'PENDING'
  | 'QUEUED'
  | 'SENT'
  | 'DELIVERED'
  | 'RECEIVED'
  | 'EXECUTING'
  | 'SUCCESS'
  | 'FAILED'
  | 'EXPIRED'
  | 'CANCELLED'

/** Tabel `device_commands` — skema dimiliki Laravel (§34). AdonisJS menulis status
 * SENT/DELIVERED/RECEIVED/EXECUTING/SUCCESS/FAILED langsung (§19-22) — jalur realtime,
 * tidak round-trip lewat Laravel. */
export default class DeviceCommand extends BaseModel {
  static table = 'device_commands'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare commandType: string

  @column()
  declare payload: Record<string, unknown> | null

  @column()
  declare status: CommandStatus

  @column()
  declare failureReason: string | null

  @column()
  declare deviceSessionId: string | null

  @column.dateTime()
  declare expiresAt: DateTime

  @column.dateTime()
  declare sentAt: DateTime | null

  @column.dateTime()
  declare deliveredAt: DateTime | null

  @column.dateTime()
  declare executedAt: DateTime | null

  @column.dateTime()
  declare completedAt: DateTime | null

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime

  @column.dateTime({ autoCreate: true, autoUpdate: true })
  declare updatedAt: DateTime
}
