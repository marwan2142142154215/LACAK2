import { BaseModel, column } from '@adonisjs/lucid/orm'
import { DateTime } from 'luxon'

/** Tabel `device_media` — skema dimiliki Laravel (§34). Ditulis AdonisJS saat command
 * CAMERA_REQUEST ber-ack SUCCESS (§27/§28). storage_path & camera_facing sudah diketahui
 * dari command.payload (dibuat Laravel saat request) — device hanya perlu konfirmasi
 * metadata hasil capture+upload yang sebenarnya terjadi. */
export default class DeviceMedia extends BaseModel {
  static table = 'device_media'

  public static selfAssignPrimaryKey = true

  @column({ isPrimary: true })
  declare id: string

  @column()
  declare deviceId: string

  @column()
  declare commandId: string | null

  @column()
  declare cameraFacing: 'FRONT' | 'BACK'

  @column()
  declare storagePath: string

  @column()
  declare mimeType: string

  @column()
  declare sizeBytes: number

  // columnName eksplisit WAJIB — konversi otomatis Lucid camelCase->snake_case akan
  // menghasilkan "sha_256_hash" (memisah di batas angka), bukan "sha256_hash" yang
  // sebenarnya ada di DB (ditemukan lewat error query nyata, bukan ditebak).
  @column({ columnName: 'sha256_hash' })
  declare sha256Hash: string

  @column.dateTime()
  declare capturedAt: DateTime

  @column.dateTime()
  declare uploadedAt: DateTime | null

  @column.dateTime({ autoCreate: true })
  declare createdAt: DateTime
}
