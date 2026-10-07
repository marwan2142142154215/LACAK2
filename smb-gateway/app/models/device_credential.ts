import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/**
 * Tabel `device_credentials` — skema dimiliki Laravel (§34). AdonisJS hanya BACA untuk
 * verifikasi saat device connect WebSocket (§43). Credential diterbitkan oleh Laravel
 * saat device registration (PHASE 9); `credential_hash` adalah bcrypt hash (kompatibel
 * PHP<->Node — kedua sisi memakai algoritma bcrypt standar, bukan hashing proprietary).
 */
export default class DeviceCredential extends BaseModel {
  static table = 'device_credentials'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare credentialHash: string

  @column()
  declare publicTokenId: string

  @column.dateTime()
  declare revokedAt: DateTime | null
}
