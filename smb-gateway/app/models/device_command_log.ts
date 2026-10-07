import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

export default class DeviceCommandLog extends BaseModel {
  static table = 'device_command_logs'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare commandId: string

  @column()
  declare fromStatus: string | null

  @column()
  declare toStatus: string

  @column()
  declare note: string | null

  @column()
  declare actor: 'DEVICE' | 'GATEWAY' | 'SYSTEM' | 'USER'

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime
}
