package com.smb.lacak.data.network

import com.smb.lacak.BuildConfig
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.device.DeviceCapabilities
// StoredDeviceCredential dipakai sebagai parameter sendHttpsHeartbeat()
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

data class DeviceRegistration(
    val code: String,
    val androidApiLevel: Int,
    val androidVersion: String,
    val appVersion: String,
    val manufacturer: String,
    val model: String,
    val capabilities: DeviceCapabilities,
)

data class HeartbeatResult(
    val accepted: Boolean,
    val status: String,
    val serverReceivedAt: String,
)

class SmbApiException(message: String, val statusCode: Int? = null) : IOException(message)

/**
 * Klien HTTP ke smb-api (Laravel). Endpoint & nama field di sini HARUS sinkron dengan
 * docs/api.md — lihat juga DeviceRegistrationRequest.php di sisi server.
 */
class SmbApiClient(
    private val baseUrl: String = BuildConfig.API_BASE_URL,
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .build(),
) {
    /**
     * POST /api/v1/devices/register (PUBLIK). Mengembalikan device_secret — HANYA
     * ditampilkan sekali oleh server, caller WAJIB segera menyimpannya via
     * DeviceCredentialStore sebelum nilai ini hilang dari memory.
     */
    fun register(input: DeviceRegistration): StoredDeviceCredential {
        val payload = JSONObject()
            .put("code", input.code.trim())
            .put("android_api_level", input.androidApiLevel)
            .put("android_version", input.androidVersion)
            .put("app_version", input.appVersion)
            .put("manufacturer", input.manufacturer)
            .put("model", input.model)
            .put("capability_report", input.capabilities.toJson())
        val response = post("/api/v1/devices/register", payload)
        val data = response.optJSONObject("data")
            ?: throw SmbApiException("Response registrasi server tidak lengkap.")
        return StoredDeviceCredential(
            deviceId = data.getString("device_id"),
            publicTokenId = data.getString("public_token_id"),
            deviceSecret = data.getString("device_secret"),
        )
    }

    /**
     * POST /api/v1/devices/heartbeat (§45 HTTPS fallback — jalur utama tetap WebSocket).
     * Device-authenticated dengan credential penuh (device_id+public_token_id+device_secret),
     * BUKAN Bearer token Sanctum — pola sama dengan handshake WebSocket (§43).
     */
    fun sendHttpsHeartbeat(credential: StoredDeviceCredential, heartbeat: JSONObject): HeartbeatResult {
        val payload = JSONObject(heartbeat.toString())
            .put("device_id", credential.deviceId)
            .put("public_token_id", credential.publicTokenId)
            .put("device_secret", credential.deviceSecret)
        val response = post("/api/v1/devices/heartbeat", payload)
        val data = response.optJSONObject("data")
            ?: throw SmbApiException("Response heartbeat server tidak lengkap.")
        return HeartbeatResult(
            accepted = data.optBoolean("accepted", false),
            status = data.optString("status", "UNKNOWN"),
            serverReceivedAt = data.optString("server_received_at", ""),
        )
    }

    private fun post(path: String, payload: JSONObject): JSONObject {
        val url = baseUrl.trimEnd('/') + path
        val request = Request.Builder()
            .url(url)
            .post(payload.toString().toRequestBody(JSON_MEDIA_TYPE))
            .header("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(body) }.getOrNull()
            if (!response.isSuccessful || json?.optBoolean("success") != true) {
                val message = json?.optString("message")
                    ?.takeIf { it.isNotBlank() }
                    ?: "Server SMB tidak dapat menyelesaikan permintaan."
                throw SmbApiException(message, response.code)
            }
            return json
        }
    }

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
