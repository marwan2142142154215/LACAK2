package com.smb.master.ble

/**
 * §15: RSSI mentah sangat noisy (multipath, orientasi, interferensi) — JANGAN dipakai
 * langsung sebagai estimasi jarak. Ini exponential moving average (EMA) sederhana:
 * deterministik, murah secara komputasi (penting untuk battery, §73), dan cukup untuk
 * tujuan klasifikasi proximity kasar (bukan pengukuran meter presisi, §15/§88).
 *
 * Bukan class Android — pure Kotlin, sehingga dapat diuji dengan JVM unit test biasa
 * tanpa emulator/device fisik (lihat RssiSmootherTest.kt).
 */
class RssiSmoother(private val alpha: Double = 0.3) {
    init {
        require(alpha in 0.0..1.0) { "alpha harus di antara 0.0 dan 1.0." }
    }

    private var smoothedValue: Double? = null

    /** @return nilai RSSI (dBm) setelah smoothing. */
    fun addSample(rawRssi: Int): Double {
        val previous = smoothedValue
        val next = if (previous == null) {
            rawRssi.toDouble()
        } else {
            alpha * rawRssi + (1 - alpha) * previous
        }
        smoothedValue = next
        return next
    }

    fun currentValue(): Double? = smoothedValue

    fun reset() {
        smoothedValue = null
    }
}
