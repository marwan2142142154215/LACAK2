package com.smb.master.ble

import java.util.UUID

/**
 * SMB BLE Proximity Protocol (lihat docs/ble.md untuk dokumentasi lengkap).
 *
 * Konstanta ini HARUS identik dengan BleProximityProtocol di smb-tracker-android
 * (SMB Lacak = peripheral/advertiser, SMB Master = central/scanner, §14). Tidak dibuat
 * sebagai Gradle module bersama karena kedua app adalah project Android independen
 * (§81 project structure) — duplikasi konstanta ini kecil & disengaja, bukan drift:
 * setiap perubahan WAJIB disinkronkan manual di kedua tempat dan dicatat di docs/ble.md.
 *
 * §16 BLE SECURITY: advertisement HANYA berisi service UUID (bukti "ini SMB Lacak") +
 * ephemeral identifier 16 byte yang DIROTASI periodik oleh Lacak. TIDAK PERNAH berisi
 * device_id, registration_code, access_token, atau secret apa pun dalam bentuk apapun.
 * Pemetaan ephemeral_id -> identitas device yang terverifikasi kriptografis (BLE
 * challenge/response via GATT) adalah peningkatan terdokumentasi yang BELUM
 * diimplementasikan di fase ini (lihat docs/ble.md §"Keterbatasan saat ini") — baseline
 * ini sudah memenuhi §16 karena tidak ada secret yang di-broadcast plaintext.
 */
object BleProximityProtocol {
    /** Service UUID custom SMB — dipakai Master untuk filter scan supaya hanya menemukan device SMB. */
    val SERVICE_UUID: UUID = UUID.fromString("6b3a0001-7b7a-4e62-9c3e-2f4f6a9d0001")

    /** Company ID placeholder untuk manufacturer-specific data (0xFFFF = unregistered/testing, §16). */
    const val MANUFACTURER_ID = 0xFFFF

    /** Panjang ephemeral identifier dalam byte, dikirim sebagai manufacturer data. */
    const val EPHEMERAL_ID_LENGTH_BYTES = 16

    /** Rotasi ephemeral identifier setiap interval ini (battery-aware, §73, juga privacy). */
    const val EPHEMERAL_ROTATION_INTERVAL_MS = 15 * 60 * 1000L

    /** Device dianggap hilang dari radar jika tidak terdeteksi lagi setelah interval ini. */
    const val NOT_DETECTED_TIMEOUT_MS = 30_000L
}
