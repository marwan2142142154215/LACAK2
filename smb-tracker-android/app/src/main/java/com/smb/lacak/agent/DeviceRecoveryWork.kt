package com.smb.lacak.agent

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * §C WorkManager — lapisan recovery TAMBAHAN di atas DeviceAgentService (foreground
 * service + Socket.IO). Kalau foreground service ditolak OS (§69) atau app process
 * mati, ini tetap bisa mengirim heartbeat HTTPS periodik (§45) walau lebih jarang
 * (15 menit — WorkManager minimum interval untuk periodic work) daripada WebSocket.
 */
object DeviceRecoveryWork {
    private const val IMMEDIATE_WORK = "smb-device-https-recovery"
    private const val PERIODIC_WORK = "smb-device-periodic-heartbeat"

    fun ensurePeriodic(context: Context) {
        val request = PeriodicWorkRequestBuilder<DeviceHeartbeatWorker>(15, TimeUnit.MINUTES)
            .setConstraints(networkConstraints())
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            PERIODIC_WORK,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    fun enqueueImmediate(context: Context) {
        val request = OneTimeWorkRequestBuilder<DeviceHeartbeatWorker>()
            .setConstraints(networkConstraints())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(IMMEDIATE_WORK, ExistingWorkPolicy.REPLACE, request)
    }

    private fun networkConstraints() = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()
}
