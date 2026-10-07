package com.smb.lacak.presentation

import androidx.test.core.app.ActivityScenario
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.espresso.Espresso.onView
import androidx.test.espresso.assertion.ViewAssertions.matches
import androidx.test.espresso.matcher.ViewMatchers.isDisplayed
import androidx.test.espresso.matcher.ViewMatchers.withText
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class MainActivitySmokeTest {
    @Test
    fun displaysRealRegistrationAndCapabilityScreen() {
        ActivityScenario.launch(MainActivity::class.java).use {
            onView(withText("SMB LACAK")).check(matches(isDisplayed()))
            onView(withText("Pendaftaran perangkat")).check(matches(isDisplayed()))
            onView(withText("Minimum Android 8.0 (API 26). Capability kamera/lokasi bergantung pada perangkat, permission, OS, dan policy."))
                .check(matches(isDisplayed()))
        }
    }
}
