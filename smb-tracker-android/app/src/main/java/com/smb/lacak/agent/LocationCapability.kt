package com.smb.lacak.agent

import android.Manifest
import android.content.Context
import android.content.Context.LOCATION_SERVICE
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import androidx.core.content.ContextCompat
import org.json.JSONObject
import java.time.Instant

/**
 * §25/§26/§70 — HANYA last-known location (bukan meminta fix GPS baru yang butuh
 * callback async + timeout kompleks). Ini bukan jalan pintas: §70 secara eksplisit
 * meminta "last known location, timestamp, accuracy, source" sebagai implementasi
 * yang JUJUR, bukan mengklaim real-time GPS kalau sebenarnya tidak selalu bisa.
 */
object LocationCapability {
    sealed class Result {
        data class Success(val payload: JSONObject) : Result()
        data class Unavailable(val reason: String) : Result()
    }

    fun requestLastKnown(context: Context): Result {
        val hasFine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val hasCoarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

        if (!hasFine && !hasCoarse) {
            return Result.Unavailable("Izin lokasi belum diberikan (ACCESS_FINE_LOCATION/ACCESS_COARSE_LOCATION).")
        }

        val manager = context.getSystemService(LOCATION_SERVICE) as? LocationManager
            ?: return Result.Unavailable("LocationManager tidak tersedia di perangkat ini.")

        val candidates = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .filter { runCatching { manager.isProviderEnabled(it) }.getOrDefault(false) }
            .mapNotNull { provider -> runCatching { manager.getLastKnownLocation(provider)?.let { provider to it } }.getOrNull() }

        val best = candidates.maxByOrNull { (_, location) -> location.time }
            ?: return Result.Unavailable("Belum ada last-known location (GPS/Network provider belum pernah fix sebelumnya).")

        val (_, location) = best
        return Result.Success(toPayload(location))
    }

    private fun toPayload(location: Location): JSONObject {
        return JSONObject()
            .put("latitude", location.latitude)
            .put("longitude", location.longitude)
            .put("accuracy", if (location.hasAccuracy()) location.accuracy.toDouble() else JSONObject.NULL)
            // §70: SELALU "LAST_KNOWN" (bukan "GPS"/"NETWORK" mentah dari nama provider) —
            // ini bukan fix baru, melainkan nilai terakhir yang pernah dicatat sistem Android,
            // walau diambil dari provider GPS. Perbedaan ini penting supaya dashboard tidak
            // salah mengartikan data ini sebagai real-time fix.
            .put("source", "LAST_KNOWN")
            .put("recorded_at", Instant.ofEpochMilli(location.time).toString())
    }
}
