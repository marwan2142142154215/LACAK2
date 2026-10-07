plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

android {
    namespace = "com.smb.lacak"
    // §Android section: compileSdk 36, targetSdk 36, minSdk 26 (Android 8.0) — WAJIB,
    // jangan dinaikkan tanpa persetujuan (lihat docs/android-compatibility.md).
    compileSdk = 36

    defaultConfig {
        applicationId = "com.smb.lacak"
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // Default dev lokal — di-override per build variant untuk production (PHASE 20/24).
        // Port 8010 (BUKAN 8000) sengaja — lihat DEC-004 di docs/DECISIONS.md: 8000 bentrok
        // dengan container Docker proyek lain di mesin dev yang kebetulan punya health
        // endpoint mirip, menyebabkan false-positive saat testing.
        buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:8010\"")
        buildConfigField("String", "WS_URL", "\"ws://10.0.2.2:3334\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            buildConfigField("String", "API_BASE_URL", "\"https://api.lacaksmbbot.com\"")
            buildConfigField("String", "WS_URL", "\"wss://ws.lacaksmbbot.com\"")
        }
        debug {
            // 10.0.2.2 = alias loopback host-machine standar emulator Android (§ testing lokal).
            buildConfigField("String", "API_BASE_URL", "\"http://10.0.2.2:8010\"")
            buildConfigField("String", "WS_URL", "\"ws://10.0.2.2:3334\"")
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    testOptions {
        unitTests.isIncludeAndroidResources = true
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.ktx)
    implementation(libs.androidx.appcompat)
    implementation(libs.material)
    implementation(libs.androidx.lifecycle.viewmodel.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.okhttp)
    implementation(libs.kotlinx.coroutines.android)

    // §43/§44: client WebSocket WAJIB bicara protokol Socket.IO (bukan raw WebSocket) karena
    // smb-gateway (AdonisJS) memakai Socket.IO server — raw OkHttp WebSocket TIDAK cocok,
    // Socket.IO punya framing paket sendiri di atas WebSocket (engine.io protocol).
    //
    // exclude org.json:json WAJIB — socket.io-client menarik org.json:json:20090211 secara
    // transitif, yang bentrok "duplicate class org.json.JSONObject" dengan org.json bawaan
    // platform Android (android.jar sudah menyediakan paket org.json sendiri). Diverifikasi
    // dari POM asli library ini, bukan asumsi.
    implementation(libs.socketio.client) {
        exclude(group = "org.json", module = "json")
    }
    // CATATAN: engine.io-client (dependency transitif socket.io-client) menarik OkHttp
    // 3.12.12 secara transitif, sementara kita deklarasikan OkHttp 4.12.0 langsung di atas.
    // Gradle default resolve ke versi TERTINGGI (4.12.0) di classpath akhir — aman karena
    // API Java publik OkHttp3 yang dipakai socket.io-client tetap kompatibel di OkHttp4.
    // Diverifikasi dari POM asli kedua library, bukan asumsi.

    // §39: credential device (device_secret) WAJIB encrypted-at-rest. Dipakai langsung
    // Android Keystore (AES-GCM) via DeviceCredentialStore — tidak perlu library tambahan
    // (androidx.security.crypto) karena implementasi manual ini sudah cukup & lebih explicit.

    testImplementation(libs.junit)
    testImplementation(libs.mockwebserver)
    androidTestImplementation(libs.androidx.test.junit)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.androidx.test.espresso)
    androidTestImplementation(libs.mockwebserver)
}
