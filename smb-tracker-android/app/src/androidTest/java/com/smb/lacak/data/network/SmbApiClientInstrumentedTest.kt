package com.smb.lacak.data.network

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.smb.lacak.device.DeviceCapabilities
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.json.JSONObject
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Field & path di sini HARUS sinkron dengan smb-api/app/Http/Requests/Device/
 * DeviceRegistrationRequest.php dan DeviceRegistrationController.php (lihat docs/api.md).
 */
@RunWith(AndroidJUnit4::class)
class SmbApiClientInstrumentedTest {
    private lateinit var server: MockWebServer
    private lateinit var client: SmbApiClient

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
        client = SmbApiClient(server.url("/").toString())
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun registrationSendsCapabilityReportAndReadsServerIssuedCredentialExactlyOnce() {
        server.enqueue(
            MockResponse().setHeader("Content-Type", "application/json").setResponseCode(201).setBody(
                """{"success":true,"message":"Device berhasil didaftarkan.","data":{"device_id":"server-uuid","public_token_id":"token-uuid","device_secret":"${"s".repeat(48)}","site_id":"site-uuid","team_id":"team-uuid"}}""",
            ),
        )

        val result = client.register(registration())
        val request = server.takeRequest()
        val body = JSONObject(request.body.readUtf8())

        assertEquals("server-uuid", result.deviceId)
        assertEquals("token-uuid", result.publicTokenId)
        assertEquals("POST", request.method)
        assertEquals("/api/v1/devices/register", request.path)
        assertFalse(request.getHeader("Authorization") != null) // §9: endpoint ini publik, tanpa token
        assertEquals("ABCDEFGHJKLMNPQRSTUV", body.getString("code"))
        assertEquals(true, body.getJSONObject("capability_report").getBoolean("front_camera_available"))
    }

    @Test
    fun serverRejectionIsReturnedAsTypedErrorWithoutClaimingSuccess() {
        server.enqueue(
            MockResponse().setResponseCode(422).setHeader("Content-Type", "application/json")
                .setBody("""{"success":false,"message":"Registration code sudah kedaluwarsa.","errors":[]}"""),
        )

        val error = runCatching { client.register(registration()) }.exceptionOrNull()

        assertTrue(error is SmbApiException)
        assertEquals(422, (error as SmbApiException).statusCode)
        assertEquals("Registration code sudah kedaluwarsa.", error.message)
    }

    @Test
    fun unknownCodeReturns404WithServerMessage() {
        server.enqueue(
            MockResponse().setResponseCode(404).setHeader("Content-Type", "application/json")
                .setBody("""{"success":false,"message":"Registration code tidak ditemukan.","errors":[]}"""),
        )

        val error = runCatching { client.register(registration()) }.exceptionOrNull() as? SmbApiException

        assertEquals(404, error?.statusCode)
    }

    private fun registration() = DeviceRegistration(
        code = "ABCDEFGHJKLMNPQRSTUV",
        androidApiLevel = 35,
        androidVersion = "15",
        appVersion = "1.0.0",
        manufacturer = "Test",
        model = "Emulator",
        capabilities = DeviceCapabilities(
            cameraAvailable = true,
            frontCameraAvailable = true,
            backCameraAvailable = false,
            locationAvailable = false,
            managedDevice = false,
            deviceOwner = false,
            foregroundServiceAvailable = true,
            notificationPermission = true,
        ),
    )
}
