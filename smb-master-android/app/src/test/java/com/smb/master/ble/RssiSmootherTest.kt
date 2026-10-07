package com.smb.master.ble

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class RssiSmootherTest {
    @Test
    fun firstSampleIsReturnedAsIs() {
        val smoother = RssiSmoother(alpha = 0.3)
        assertEquals(-60.0, smoother.addSample(-60), 0.0001)
    }

    @Test
    fun subsequentSamplesAreExponentiallyWeighted() {
        val smoother = RssiSmoother(alpha = 0.5)
        smoother.addSample(-60)
        val second = smoother.addSample(-80)
        // EMA: 0.5 * -80 + 0.5 * -60 = -70
        assertEquals(-70.0, second, 0.0001)
    }

    @Test
    fun noisySpikeIsDampenedNotFollowedDirectly() {
        val smoother = RssiSmoother(alpha = 0.2)
        repeat(5) { smoother.addSample(-60) }
        val afterSpike = smoother.addSample(-95)
        // Nilai smoothed harus jauh lebih dekat ke -60 (baseline) daripada ke -95 (spike tunggal).
        assert(afterSpike > -70) { "Spike tunggal seharusnya tidak langsung menggeser nilai smoothed drastis: $afterSpike" }
    }

    @Test
    fun resetClearsState() {
        val smoother = RssiSmoother()
        smoother.addSample(-60)
        smoother.reset()
        assertNull(smoother.currentValue())
    }

    @Test(expected = IllegalArgumentException::class)
    fun alphaOutOfRangeIsRejected() {
        RssiSmoother(alpha = 1.5)
    }
}
