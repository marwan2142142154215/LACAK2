package com.smb.master.ble

/**
 * §14/§15 — satu entri radar BLE Master. `ephemeralId` adalah identifier rotating yang
 * di-broadcast Lacak (§16), BUKAN device_id sesungguhnya — lihat docs/ble.md untuk
 * batasan pemetaan identitas saat ini.
 */
data class NearbyDevice(
    val ephemeralId: String,
    val lastRssiDbm: Int,
    val smoothedRssiDbm: Double,
    val proximity: ProximityLevel,
    val estimatedDistanceMeters: Double?,
    val firstSeenAtMs: Long,
    val lastSeenAtMs: Long,
)

/** §50 — status subsistem BLE itu sendiri (bukan status satu device), ditampilkan di UI Master. */
sealed class BleScanState {
    data object Idle : BleScanState()
    data object BleDisabled : BleScanState()
    data object PermissionRequired : BleScanState()
    data object Scanning : BleScanState()
    data class Error(val reason: String) : BleScanState()
}
