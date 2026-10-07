package com.smb.master.data.network

import com.smb.master.BuildConfig
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

class MasterApiException(message: String, val statusCode: Int? = null) : IOException(message)

sealed class LoginOutcome {
    data class Authenticated(
        val token: String,
        val userId: String,
        val name: String,
        val email: String,
        val roles: List<String>,
        val permissions: List<String>,
    ) : LoginOutcome()

    data class TwoFactorRequired(val loginToken: String, val expiresInSeconds: Int) : LoginOutcome()
}

data class DeviceOverview(
    val total: Int,
    val online: Int,
    val degraded: Int,
    val offline: Int,
    val locked: Int,
    val unknown: Int,
)

data class DeviceSummary(
    val id: String,
    val deviceId: String,
    val name: String,
    val status: String,
    val siteName: String?,
    val teamName: String?,
    val androidVersion: String?,
    val appVersion: String?,
    val lastHeartbeatAt: String?,
    val lastSeenAt: String?,
)

data class CommandResult(val commandId: String, val status: String)

/**
 * Klien HTTP SMB Master -> smb-api (Laravel), §27/§30. Memakai endpoint ADMIN yang SAMA
 * dengan SMB Web (bearer token Sanctum, §9/§30) — bukan jalur device-credential seperti
 * SmbApiClient di smb-tracker-android. Field & path WAJIB sinkron dengan docs/api.md.
 *
 * §18/§19: Master TIDAK PERNAH mengirim command langsung ke Lacak — semua command lewat
 * endpoint ini -> Laravel -> command broker -> AdonisJS gateway -> device (§18).
 */
class MasterApiClient(
    private val baseUrl: String = BuildConfig.API_BASE_URL,
    private var bearerToken: String? = null,
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .writeTimeout(20, TimeUnit.SECONDS)
        .build(),
) {
    fun setToken(token: String?) {
        bearerToken = token
    }

    /** POST /api/v1/auth/login — §30. Tidak langsung mengeluarkan token kalau 2FA aktif. */
    fun login(email: String, password: String, deviceName: String): LoginOutcome {
        val payload = JSONObject()
            .put("email", email.trim())
            .put("password", password)
            .put("device_name", deviceName)
        val response = post("/api/v1/auth/login", payload, authenticated = false)
        val data = response.optJSONObject("data") ?: throw MasterApiException("Response login server tidak lengkap.")

        if (data.optBoolean("two_factor_required", false)) {
            return LoginOutcome.TwoFactorRequired(
                loginToken = data.getString("login_token"),
                expiresInSeconds = data.optInt("expires_in", 300),
            )
        }
        return authenticatedFromData(data)
    }

    /** POST /api/v1/auth/two-factor-challenge — §30 step-up kedua setelah login_token diterima. */
    fun verifyTwoFactor(loginToken: String, code: String? = null, recoveryCode: String? = null): LoginOutcome.Authenticated {
        val payload = JSONObject().put("login_token", loginToken)
        if (!code.isNullOrBlank()) payload.put("code", code)
        if (!recoveryCode.isNullOrBlank()) payload.put("recovery_code", recoveryCode)
        val response = post("/api/v1/auth/two-factor-challenge", payload, authenticated = false)
        val data = response.optJSONObject("data") ?: throw MasterApiException("Response verifikasi 2FA server tidak lengkap.")
        return authenticatedFromData(data)
    }

    /** POST /api/v1/auth/logout — §30 revoke token server-side, bukan cuma hapus lokal. */
    fun logout() {
        runCatching { post("/api/v1/auth/logout", JSONObject(), authenticated = true) }
    }

    /** GET /api/v1/devices/overview — §28 kartu ringkasan. */
    fun deviceOverview(): DeviceOverview {
        val data = get("/api/v1/devices/overview").optJSONObject("data") ?: JSONObject()
        return DeviceOverview(
            total = data.optInt("total"),
            online = data.optInt("online"),
            degraded = data.optInt("degraded"),
            offline = data.optInt("offline"),
            locked = data.optInt("locked"),
            unknown = data.optInt("unknown"),
        )
    }

    /** GET /api/v1/devices — §27/§28 daftar+filter+search. */
    fun listDevices(search: String? = null, siteId: String? = null, teamId: String? = null): List<DeviceSummary> {
        val query = buildString {
            append("/api/v1/devices?per_page=100")
            if (!search.isNullOrBlank()) append("&search=").append(urlEncode(search))
            if (!siteId.isNullOrBlank()) append("&site_id=").append(urlEncode(siteId))
            if (!teamId.isNullOrBlank()) append("&team_id=").append(urlEncode(teamId))
        }
        val response = get(query)
        val items = response.optJSONArray("data") ?: JSONArray()
        return (0 until items.length()).map { index -> parseDeviceSummary(items.getJSONObject(index)) }
    }

    /** POST /api/v1/devices/{device}/lock — §23/§27. */
    fun lockDevice(deviceId: String, message: String? = null): CommandResult {
        val payload = JSONObject()
        if (!message.isNullOrBlank()) payload.put("message", message)
        val data = post("/api/v1/devices/$deviceId/lock", payload, authenticated = true).optJSONObject("data") ?: JSONObject()
        return CommandResult(data.optString("command_id"), data.optString("status"))
    }

    /** POST /api/v1/devices/{device}/unlock — §24/§27, jalur admin (bukan OTP). */
    fun unlockDevice(deviceId: String): CommandResult {
        val data = post("/api/v1/devices/$deviceId/unlock", JSONObject(), authenticated = true).optJSONObject("data") ?: JSONObject()
        return CommandResult(data.optString("command_id"), data.optString("status"))
    }

    /** POST /api/v1/devices/{device}/location/request — §25/§27. */
    fun requestLocation(deviceId: String): CommandResult {
        val data = post("/api/v1/devices/$deviceId/location/request", JSONObject(), authenticated = true).optJSONObject("data") ?: JSONObject()
        return CommandResult(data.optString("command_id"), data.optString("status"))
    }

    /** GET /api/v1/devices/{device}/locations/latest — §25/§27. null kalau belum ada data (404 jujur, bukan fake). */
    fun latestLocation(deviceId: String): JSONObject? = runCatching {
        get("/api/v1/devices/$deviceId/locations/latest").optJSONObject("data")
    }.getOrNull()

    private fun authenticatedFromData(data: JSONObject): LoginOutcome.Authenticated {
        val user = data.optJSONObject("user") ?: JSONObject()
        bearerToken = data.getString("token")
        return LoginOutcome.Authenticated(
            token = data.getString("token"),
            userId = user.optString("id"),
            name = user.optString("name"),
            email = user.optString("email"),
            roles = jsonArrayToStringList(user.optJSONArray("roles")),
            permissions = jsonArrayToStringList(user.optJSONArray("permissions")),
        )
    }

    private fun parseDeviceSummary(json: JSONObject): DeviceSummary = DeviceSummary(
        id = json.optString("id"),
        deviceId = json.optString("device_id"),
        name = json.optString("name"),
        status = json.optString("status", "UNKNOWN"),
        siteName = json.optJSONObject("site")?.optString("name"),
        teamName = json.optJSONObject("team")?.optString("name"),
        androidVersion = json.optString("android_version").takeIf { it.isNotBlank() },
        appVersion = json.optString("app_version").takeIf { it.isNotBlank() },
        lastHeartbeatAt = json.optString("last_heartbeat_at").takeIf { it.isNotBlank() },
        lastSeenAt = json.optString("last_seen_at").takeIf { it.isNotBlank() },
    )

    private fun jsonArrayToStringList(array: JSONArray?): List<String> {
        if (array == null) return emptyList()
        return (0 until array.length()).map { array.getString(it) }
    }

    private fun urlEncode(value: String): String = java.net.URLEncoder.encode(value, "UTF-8")

    private fun get(path: String): JSONObject {
        val request = Request.Builder()
            .url(baseUrl.trimEnd('/') + path)
            .get()
            .header("Accept", "application/json")
            .applyAuth()
            .build()
        return execute(request)
    }

    private fun post(path: String, payload: JSONObject, authenticated: Boolean): JSONObject {
        val builder = Request.Builder()
            .url(baseUrl.trimEnd('/') + path)
            .post(payload.toString().toRequestBody(JSON_MEDIA_TYPE))
            .header("Accept", "application/json")
        if (authenticated) builder.applyAuth()
        return execute(builder.build())
    }

    private fun Request.Builder.applyAuth(): Request.Builder {
        val token = bearerToken ?: throw MasterApiException("Sesi login tidak tersedia; silakan login ulang.")
        return header("Authorization", "Bearer $token")
    }

    private fun execute(request: Request): JSONObject {
        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            val json = runCatching { JSONObject(body) }.getOrNull()
            if (!response.isSuccessful || json?.optBoolean("success") != true) {
                val message = json?.optString("message")?.takeIf { it.isNotBlank() }
                    ?: "Server SMB tidak dapat menyelesaikan permintaan."
                throw MasterApiException(message, response.code)
            }
            return json
        }
    }

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
