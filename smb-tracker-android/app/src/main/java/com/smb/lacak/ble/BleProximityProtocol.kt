package com.smb.lacak.ble

import java.util.UUID

/**
 * SMB BLE Proximity Protocol (lihat docs/ble.md). Konstanta ini HARUS identik dengan
 * BleProximityProtocol di smb-master-android (Lacak = peripheral/advertiser, Master =
 * central/scanner, §14). Duplikasi disengaja (§81 — dua project Android independen,
 * bukan Gradle module bersama) — setiap perubahan WAJIB disinkronkan manual di kedua
 * tempat dan dicatat di docs/ble.md.
 *
 * §16 BLE SECURITY: advertisement HANYA berisi service UUID + ephemeral identifier 16
 * byte yang DIROTASI periodik — TIDAK PERNAH device_id/registration_code/access_token/secret.
 */
object BleProximityProtocol {
    val SERVICE_UUID: UUID = UUID.fromString("6b3a0001-7b7a-4e62-9c3e-2f4f6a9d0001")
    const val MANUFACTURER_ID = 0xFFFF
    const val EPHEMERAL_ID_LENGTH_BYTES = 16
    const val EPHEMERAL_ROTATION_INTERVAL_MS = 15 * 60 * 1000L
}
