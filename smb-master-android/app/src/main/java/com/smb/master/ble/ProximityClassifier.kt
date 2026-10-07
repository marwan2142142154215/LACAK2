package com.smb.master.ble

/**
 * §15: output klasifikasi proximity dari RSSI yang sudah di-smoothing. Threshold dalam
 * dBm ini adalah KALIBRASI KASAR (generik, bukan per-perangkat/per-chipset) — didokumentasikan
 * secara eksplisit sebagai perkiraan di docs/ble.md, konsisten dengan §15/§88 ("jangan
 * menjanjikan akurasi jarak BLE 100%"). Perangkat yang membutuhkan presisi lebih tinggi
 * memerlukan kalibrasi per-model yang di luar scope fase ini.
 */
enum class ProximityLevel {
    VERY_NEAR,
    NEAR,
    MEDIUM,
    FAR,
    NOT_DETECTED,
}

object ProximityClassifier {
    private const val VERY_NEAR_THRESHOLD_DBM = -50.0
    private const val NEAR_THRESHOLD_DBM = -65.0
    private const val MEDIUM_THRESHOLD_DBM = -80.0

    fun classify(smoothedRssi: Double?): ProximityLevel {
        if (smoothedRssi == null) return ProximityLevel.NOT_DETECTED
        return when {
            smoothedRssi >= VERY_NEAR_THRESHOLD_DBM -> ProximityLevel.VERY_NEAR
            smoothedRssi >= NEAR_THRESHOLD_DBM -> ProximityLevel.NEAR
            smoothedRssi >= MEDIUM_THRESHOLD_DBM -> ProximityLevel.MEDIUM
            else -> ProximityLevel.FAR
        }
    }

    /**
     * §15: "estimated_distance" WAJIB diberi label "Perkiraan jarak", bukan jarak absolut.
     * Formula log-distance path loss standar dengan n=2 (free-space) dan RSSI@1m=-59dBm
     * sebagai referensi generik — bukan hasil kalibrasi perangkat nyata (§88 kejujuran).
     */
    fun estimatedDistanceMeters(smoothedRssi: Double?): Double? {
        if (smoothedRssi == null) return null
        val referenceRssiAt1m = -59.0
        val pathLossExponent = 2.0
        return Math.pow(10.0, (referenceRssiAt1m - smoothedRssi) / (10 * pathLossExponent))
    }
}
