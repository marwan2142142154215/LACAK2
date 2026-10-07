package com.smb.master.data.network

import androidx.test.ext.junit.runners.AndroidJUnit4
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Field & path di sini HARUS sinkron dengan smb-api/app/Http/Controllers/Api/V1/Auth/
 * LoginController.php, TwoFactorChallengeController.php, DeviceController.php,
 * DeviceLockController.php, DeviceLocationController.php (lihat docs/api.md).
 */
@RunWith(AndroidJUnit4::class)
class MasterApiClientInstrumentedTest {
    private lateinit var server: MockWebServer
    private lateinit var client: MasterApiClient

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
        client = MasterApiClient(server.url("/").toString())
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun loginWithoutTwoFactorReturnsAuthenticatedSessionImmediately() {
        server.enqueue(
            MockResponse().setHeader("Content-Type", "application/json").setResponseCode(200).setBody(
                """{"success":true,"message":"Login berhasil.","data":{"token":"plain-text-token","user":{"id":"u-1","name":"Operator","email":"op@example.com","roles":["ADMIN"],"permissions":["devices.lock"]}}}""",
            ),
        )

        val outcome = client.login("op@example.com", "secret", "Pixel 8")
        val request = server.takeRequest()

        assertTrue(outcome is LoginOutcome.Authenticated)
        assertEquals("/api/v1/auth/login", request.path)
        assertEquals("op@example.com", JSONObject(request.body.readUtf8()).getString("email"))
        assertEquals("plain-text-token", (outcome as LoginOutcome.Authenticated).token)
    }

    @Test
    fun loginWithTwoFactorEnabledDoesNotIssueTokenYet() {
        server.enqueue(
            MockResponse().setHeader("Content-Type", "application/json").setResponseCode(200).setBody(
                """{"success":true,"message":"Verifikasi 2FA diperlukan.","data":{"two_factor_required":true,"login_token":"abc123","expires_in":300}}""",
            ),
        )

        val outcome = client.login("op@example.com", "secret", "Pixel 8")

        assertTrue(outcome is LoginOutcome.TwoFactorRequired)
        assertEquals("abc123", (outcome as LoginOutcome.TwoFactorRequired).loginToken)
    }

    @Test
    fun invalidCredentialsSurfaceServerMessageNotFakeSuccess() {
        server.enqueue(
            MockResponse().setResponseCode(401).setHeader("Content-Type", "application/json")
                .setBody("""{"success":false,"message":"Email atau password salah.","errors":[]}"""),
        )

        val error = runCatching { client.login("op@example.com", "wrong", "Pixel 8") }.exceptionOrNull()

        assertTrue(error is MasterApiException)
        assertEquals(401, (error as MasterApiException).statusCode)
        assertEquals("Email atau password salah.", error.message)
    }

    @Test
    fun deviceListRequestCarriesBearerTokenAndParsesSummaries() {
        client.setToken("session-token")
        server.enqueue(
            MockResponse().setHeader("Content-Type", "application/json").setResponseCode(200).setBody(
                """{"success":true,"message":"Daftar device.","data":[{"id":"d-1","device_id":"uuid-1","name":"Gudang A","status":"ONLINE","site":{"name":"Jakarta"},"team":{"name":"Ops"},"android_version":"14","last_heartbeat_at":"2026-10-07T10:00:00Z"}]}""",
            ),
        )

        val devices = client.listDevices()
        val request = server.takeRequest()

        assertEquals("Bearer session-token", request.getHeader("Authorization"))
        assertEquals(1, devices.size)
        assertEquals("Gudang A", devices.first().name)
        assertEquals("Jakarta", devices.first().siteName)
    }

    @Test
    fun lockDeviceWithoutTokenFailsClosedInsteadOfSendingUnauthenticatedRequest() {
        val error = runCatching { client.lockDevice("d-1") }.exceptionOrNull()
        assertTrue(error is MasterApiException)
        assertEquals(0, server.requestCount)
    }
}
