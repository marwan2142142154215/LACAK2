package com.smb.lacak.presentation

import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Bundle
import android.widget.TextView
import androidx.core.content.ContextCompat
import com.smb.lacak.R
import com.smb.lacak.agent.AgentLogger
import com.smb.lacak.agent.LockStateStore

/**
 * §23 — ditampilkan full-screen saat command LOCK diterima. HANYA berhasil "mengunci"
 * sungguhan (Lock Task Mode) kalau aplikasi adalah Device Owner (§7/§69) — kalau bukan,
 * TIDAK berpura-pura berhasil: activity ini tetap tampil sebagai overlay pesan, tapi
 * tombol Home/Recent Apps Android TIDAK diblokir (itu bukan "lock" sungguhan, dan
 * mengklaimnya begitu akan melanggar §66 no fake implementation).
 *
 * TIDAK ADA accessibility abuse / exploit / permission bypass di sini — hanya
 * `startLockTask()` resmi yang didokumentasikan Android untuk Device Owner (§7/§23).
 */
class LockActivity : Activity() {
    private var unlockReceiver: BroadcastReceiver? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_lock)

        val message = intent.getStringExtra(EXTRA_MESSAGE) ?: "Segera kembali ke tempat asal anda"
        val commandId = intent.getStringExtra(EXTRA_COMMAND_ID)
        findViewById<TextView>(R.id.lockMessageText).text = message

        val policyManager = getSystemService(DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val isDeviceOwner = policyManager.isDeviceOwnerApp(packageName)

        if (isDeviceOwner) {
            runCatching { startLockTask() }
                .onSuccess {
                    LockStateStore.setLocked(this, true)
                    AgentLogger.info(null, "Lock task started (Device Owner).", commandId)
                    broadcastResult(commandId, success = true, reason = null)
                }
                .onFailure { error ->
                    AgentLogger.warn(null, "startLockTask() failed: ${error.javaClass.simpleName}", commandId)
                    broadcastResult(commandId, success = false, reason = "Lock task gagal dimulai: ${error.javaClass.simpleName}")
                    finish()
                }
        } else {
            // §7/§69: jujur — fitur butuh perangkat terkelola, bukan dipalsukan berhasil.
            findViewById<TextView>(R.id.lockCapabilityNotice).text =
                getString(R.string.lock_requires_managed_device)
            AgentLogger.warn(null, "LOCK command received but device is not Device Owner.", commandId)
            broadcastResult(commandId, success = false, reason = "Fitur membutuhkan perangkat terkelola (Device Owner).")
        }

        unlockReceiver = object : BroadcastReceiver() {
            override fun onReceive(context: Context, intent: Intent) {
                runCatching { stopLockTask() }
                LockStateStore.setLocked(this@LockActivity, false)
                finish()
            }
        }
        ContextCompat.registerReceiver(this, unlockReceiver, IntentFilter(ACTION_UNLOCK), ContextCompat.RECEIVER_NOT_EXPORTED)
    }

    override fun onDestroy() {
        unlockReceiver?.let { runCatching { unregisterReceiver(it) } }
        super.onDestroy()
    }

    private fun broadcastResult(commandId: String?, success: Boolean, reason: String?) {
        sendBroadcast(
            Intent(ACTION_LOCK_RESULT)
                .setPackage(packageName)
                .putExtra(EXTRA_COMMAND_ID, commandId)
                .putExtra(EXTRA_SUCCESS, success)
                .putExtra(EXTRA_REASON, reason),
        )
    }

    companion object {
        const val EXTRA_MESSAGE = "message"
        const val EXTRA_COMMAND_ID = "command_id"
        const val EXTRA_SUCCESS = "success"
        const val EXTRA_REASON = "reason"
        const val ACTION_UNLOCK = "com.smb.lacak.action.UNLOCK"
        const val ACTION_LOCK_RESULT = "com.smb.lacak.action.LOCK_RESULT"

        fun lockIntent(context: Context, message: String, commandId: String) =
            Intent(context, LockActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                .putExtra(EXTRA_MESSAGE, message)
                .putExtra(EXTRA_COMMAND_ID, commandId)
    }
}
