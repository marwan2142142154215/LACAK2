package com.smb.lacak.agent

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.smb.lacak.data.network.SmbApiClient
import com.smb.lacak.data.network.SmbApiException
import com.smb.lacak.data.security.DeviceCredentialStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * §45 HTTPS fallback — dijalankan WorkManager (§C) saat socket WebSocket tidak
 * tersambung (DeviceAgentService tidak jalan, atau foreground service ditolak OS).
 * TIDAK mengklaim ONLINE kalau request gagal (§69/§70).
 */
class DeviceHeartbeatWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result {
        val credentialStore = DeviceCredentialStore(applicationContext)
        val statusStore = AgentStatusStore(applicationContext)
        val credential = runCatching { credentialStore.load() }.getOrNull() ?: return Result.failure()

        return withContext(Dispatchers.IO) {
            try {
                val api = SmbApiClient()
                val heartbeat = DeviceHeartbeatPayload.create(applicationContext)
                val result = api.sendHttpsHeartbeat(credential, heartbeat)
                statusStore.write(
                    result.status,
                    if (result.accepted) {
                        "Heartbeat HTTPS fallback diterima server (${result.serverReceivedAt})."
                    } else {
                        "Server menolak heartbeat HTTPS fallback."
                    },
                )
                AgentLogger.info(credential.deviceId, "HTTPS fallback heartbeat completed.")
                Result.success()
            } catch (error: SmbApiException) {
                val detail = if (error.statusCode == 401) {
                    "Credential perangkat ditolak server; hubungi admin untuk registrasi ulang."
                } else {
                    "Heartbeat fallback gagal (${error.statusCode ?: "?"}); mencoba lagi dengan backoff."
                }
                statusStore.write("DEGRADED", detail)
                AgentLogger.warn(credential.deviceId, "HTTPS fallback failed: ${error.message}")
                if (error.statusCode == 401) Result.failure() else Result.retry()
            } catch (error: Exception) {
                statusStore.write("DEGRADED", "Heartbeat fallback gagal; tidak ada jaringan atau server tidak terjangkau.")
                AgentLogger.warn(credential.deviceId, "HTTPS fallback failed: ${error.javaClass.simpleName}.")
                Result.retry()
            }
        }
    }
}
