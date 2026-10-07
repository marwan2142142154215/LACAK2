package com.smb.lacak.data

import android.content.Context
import android.os.Build
import com.smb.lacak.BuildConfig
import com.smb.lacak.agent.AgentStatusStore
import com.smb.lacak.agent.DeviceRecoveryWork
import com.smb.lacak.data.network.DeviceRegistration
import com.smb.lacak.data.network.SmbApiClient
import com.smb.lacak.data.security.DeviceCredentialStore
import com.smb.lacak.data.security.StoredDeviceCredential
import com.smb.lacak.device.DeviceCapabilitiesReporter

class DeviceRepository(context: Context) {
    private val appContext = context.applicationContext
    private val api = SmbApiClient()
    private val credentialStore = DeviceCredentialStore(appContext)

    fun register(registrationCode: String): StoredDeviceCredential {
        val registration = DeviceRegistration(
            code = registrationCode,
            androidApiLevel = Build.VERSION.SDK_INT,
            androidVersion = Build.VERSION.RELEASE ?: "UNKNOWN",
            appVersion = BuildConfig.VERSION_NAME,
            manufacturer = Build.MANUFACTURER.orEmpty().take(50),
            model = Build.MODEL.orEmpty().take(100),
            capabilities = DeviceCapabilitiesReporter.collect(appContext),
        )
        val issuedCredential = api.register(registration)
        try {
            credentialStore.save(issuedCredential)
        } catch (error: Exception) {
            // §18: device_secret TIDAK BISA diambil ulang dari server. Kalau gagal
            // tersimpan di Keystore, device SUDAH teregister di server tapi Android
            // tidak punya cara pakai device itu lagi — harus registrasi device BARU
            // dengan kode baru (device lama di-nonaktifkan admin secara manual).
            throw CredentialStorageException(
                "Server mengonsumsi kode registrasi, tetapi credential tidak dapat disimpan di Android Keystore. " +
                    "Device sudah terdaftar di server namun tidak dapat dipakai dari aplikasi ini — " +
                    "minta admin membuat kode registrasi baru.",
                error,
            )
        }
        DeviceRecoveryWork.ensurePeriodic(appContext)
        AgentStatusStore(appContext).write("REGISTERED", "Perangkat terdaftar; mulai koneksi untuk terhubung ke server.")
        return issuedCredential
    }

    fun loadCredential(): StoredDeviceCredential? = credentialStore.load()

    fun capabilitiesSummary(): String {
        val caps = DeviceCapabilitiesReporter.collect(appContext)
        val yes = "tersedia"
        val no = "belum tersedia"
        return "Android API ${Build.VERSION.SDK_INT} · Kamera depan ${if (caps.frontCameraAvailable) yes else no} · " +
            "Kamera belakang ${if (caps.backCameraAvailable) yes else no} · " +
            "Lokasi ${if (caps.locationAvailable) yes else no} · " +
            "Managed device ${if (caps.managedDevice) yes else no}"
    }
}

class CredentialStorageException(message: String, cause: Throwable) : Exception(message, cause)
