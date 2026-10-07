package com.smb.master.ble

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ProximityClassifierTest {
    @Test
    fun nullRssiIsNotDetected() {
        assertEquals(ProximityLevel.NOT_DETECTED, ProximityClassifier.classify(null))
        assertNull(ProximityClassifier.estimatedDistanceMeters(null))
    }

    @Test
    fun strongSignalIsVeryNear() {
        assertEquals(ProximityLevel.VERY_NEAR, ProximityClassifier.classify(-45.0))
    }

    @Test
    fun moderateSignalIsNear() {
        assertEquals(ProximityLevel.NEAR, ProximityClassifier.classify(-60.0))
    }

    @Test
    fun weakerSignalIsMedium() {
        assertEquals(ProximityLevel.MEDIUM, ProximityClassifier.classify(-75.0))
    }

    @Test
    fun veryWeakSignalIsFar() {
        assertEquals(ProximityLevel.FAR, ProximityClassifier.classify(-90.0))
    }

    @Test
    fun boundaryValuesClassifyToStrongerSide() {
        assertEquals(ProximityLevel.VERY_NEAR, ProximityClassifier.classify(-50.0))
        assertEquals(ProximityLevel.NEAR, ProximityClassifier.classify(-65.0))
        assertEquals(ProximityLevel.MEDIUM, ProximityClassifier.classify(-80.0))
    }

    @Test
    fun estimatedDistanceIncreasesAsSignalWeakens() {
        val near = ProximityClassifier.estimatedDistanceMeters(-50.0)!!
        val far = ProximityClassifier.estimatedDistanceMeters(-90.0)!!
        assertTrue("Perangkat dengan RSSI lebih lemah harus punya perkiraan jarak lebih jauh", far > near)
    }

    @Test
    fun distanceAtReferenceRssiIsApproximatelyOneMeter() {
        val distance = ProximityClassifier.estimatedDistanceMeters(-59.0)!!
        assertEquals(1.0, distance, 0.05)
    }
}
