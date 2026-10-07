package com.smb.lacak.agent

import android.content.Context
import androidx.core.content.edit

/**
 * §23/§24: status lock disimpan lokal supaya bertahan lintas restart proses/service —
 * UNLOCK harus tetap bisa "berhasil" (idempotent, mencapai state unlocked) walau
 * DeviceAgentService baru saja restart dan kehilangan referensi in-memory ke LockActivity.
 */
object LockStateStore {
    private const val PREFS = "smb_lacak_lock_state"
    private const val KEY_LOCKED = "is_locked"

    fun isLocked(context: Context): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(KEY_LOCKED, false)

    fun setLocked(context: Context, locked: Boolean) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit { putBoolean(KEY_LOCKED, locked) }
    }
}
