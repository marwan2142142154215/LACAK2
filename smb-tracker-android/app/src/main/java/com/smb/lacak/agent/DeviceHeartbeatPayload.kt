package com.smb.lacak.agent

import android.content.Context
import android.os.Build
import com.smb.lacak.BuildConfig
import com.smb.lacak.device.DeviceCapabilitiesReporter
import org.json.JSONObject
import java.time.Instant
import java.util.UUID

/**
 * §6: payload heartbeat — field yang tidak terbaca (izin ditolak dsb.) dikirim null,
 * BUKAN nilai palsu yang kelihatan valid (§69/§70).
 */
object DeviceHeartbeatPayload {
    fun create(context: Context): JSONObject {
        val battery = DeviceCapabilitiesReporter.batteryPercent(context)
        return JSONObject()
            .put("request_id", UUID.randomUUID().toString())
            .put("recorded_at", Instant.now().toString())
            .put("battery_level", battery ?: JSONObject.NULL)
            .put("network_type", DeviceCapabilitiesReporter.networkType(context))
            .put("connection_state", "CONNECTED")
            .put("app_version", BuildConfig.VERSION_NAME)
            .put("android_version", Build.VERSION.RELEASE ?: "UNKNOWN")
    }
}
