package com.smb.lacak

import android.app.Application
import com.smb.lacak.agent.DeviceRecoveryWork

class SmbLacakApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        // §C: lapisan recovery periodik (15 menit) — selalu aktif, terlepas foreground
        // service berjalan atau tidak. Lihat DeviceRecoveryWork untuk alasan intervalnya.
        DeviceRecoveryWork.ensurePeriodic(this)
    }
}
