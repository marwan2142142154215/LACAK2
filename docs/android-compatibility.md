# SMB Lacak — Android Compatibility Matrix

PHASE 10 deliverable. Target: **minSdk 26 (Android 8.0) — targetSdk/compileSdk 36 (Android 16)**, sesuai requirement §3.

## Status jujur per 2026-10-07

**PENTING** — dicatat terus terang, bukan diklaim selesai: build/eksekusi nyata APK ini **belum pernah dijalankan dalam sesi ini**, karena Gradle tidak bisa dijalankan dari tool otomasi yang dipakai (loopback TCP antar-proses diblokir sandbox-nya — diverifikasi konkret, bukan asumsi, lihat `docs/DECISIONS.md` DEC-003). Toolchain (JDK 17, Android SDK platform 35/36/37, emulator `lacak-api35`/`lacak-api36`) sudah tersedia di mesin ini dari sesi sebelumnya, tapi eksekusi `gradlew` harus dijalankan manual oleh pemilik proyek di terminal biasa (bukan lewat sesi agent ini).

Semua klaim di bawah ini adalah **status desain/implementasi kode**, bukan hasil test yang sudah dijalankan — kolom "Status" mencerminkan ini secara eksplisit.

## Matrix (§5)

| Android | API | Platform SDK terpasang | AVD tersedia | Status |
|---|---|---|---|---|
| 8.0 | 26 | ❌ belum | ❌ | Kode target minSdk=26, compile-time check via `Build.VERSION.SDK_INT` — **belum pernah dijalankan fisik di API 26** |
| 8.1 | 27 | ❌ | ❌ | Sama seperti di atas |
| 9 | 28 | ❌ | ❌ | Sama |
| 10 | 29 | ❌ | ❌ | Sama |
| 11 | 30 | ❌ | ❌ | Sama |
| 12 | 31 | ❌ | ❌ | Sama |
| 12L | 32 | ❌ | ❌ | Sama |
| 13 | 33 | ❌ | ❌ | Sama |
| 14 | 34 | ❌ | ❌ | Sama |
| 15 | 35 | ✅ | ✅ `lacak-api35` | Siap dijalankan pemilik proyek, belum dieksekusi sesi ini |
| 16 | 36 | ✅ | ✅ `lacak-api36` | Siap dijalankan pemilik proyek, belum dieksekusi sesi ini |

**Tindak lanjut wajib sebelum PHASE 10 dianggap "verified":** pemilik proyek menjalankan minimal:
```bash
cd smb-tracker-android
./gradlew assembleDebug
./gradlew testDebugUnitTest
./gradlew connectedDebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.class=com.smb.lacak
```
terhadap emulator `lacak-api35`/`lacak-api36` yang sudah ada, lalu melaporkan hasilnya kembali. Untuk API 26-34, perlu `sdkmanager "platforms;android-XX"` + `avdmanager create avd` dulu (platform belum terpasang di mesin ini).

## Yang sudah diimplementasikan (PHASE 10)

- Capability detection jujur (`DeviceCapabilitiesReporter`) — camera/location/managed-device/notification dibaca dari API Android sebenarnya, bukan diasumsikan
- Credential storage terenkripsi (Android Keystore AES-GCM) — bukan SharedPreferences plaintext
- Registration flow lengkap (UI → `SmbApiClient` → `POST /api/v1/devices/register`)
- Foreground service dengan `ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE` (API 34+) dan fallback untuk API < 34
- Boot receiver (`BOOT_COMPLETED` + `MY_PACKAGE_REPLACED`) dengan penanganan jujur saat Android menolak start background service (API 31+ restriction)
- WebSocket via Socket.IO client resmi (`io.socket:socket.io-client`), **bukan** raw OkHttp WebSocket — diverifikasi lewat inspeksi bytecode jar asli karena tidak bisa compile-check di sesi ini (lihat `app/build.gradle.kts` komentar)
- `NetworkCallback` untuk reconnect cepat saat jaringan pulih

## Yang BELUM ada (menyusul PHASE 11+)

- Heartbeat payload + endpoint Laravel (`DeviceHeartbeatWorker`, WorkManager periodic recovery)
- Lock/unlock, location, camera capability execution (PHASE 13-16)
- Update strategy (§51)

## Keterbatasan Android yang didokumentasikan (§68-70)

| Keterbatasan | Alasan | Permission/policy dibutuhkan | Fallback |
|---|---|---|---|
| Foreground service bisa ditolak OS | Battery optimization / background start restriction (API 31+) | `POST_NOTIFICATIONS`, user exempt dari battery optimization | Status `DEGRADED` dilaporkan jujur ke UI; WorkManager recovery (PHASE 11) |
| Background location terbatas | Android 10+ membedakan foreground/background location permission | `ACCESS_BACKGROUND_LOCATION` (belum diminta — hanya foreground untuk saat ini) | `capability_report.location_available = false` jika tidak diizinkan |
| Camera capture di background | Android 10+ membatasi akses kamera dari background app | Managed-device policy / foreground context saat capture | `CAMERA_UNAVAILABLE` (diimplementasikan PHASE 16) |
| Device Owner | Hanya tersedia lewat provisioning Android Enterprise resmi (QR/zero-touch) saat setup awal device, tidak bisa diklaim retroaktif | Provisioning sebelum akun Google pertama ditambahkan | `capability_report.device_owner = false`, fitur terkait ditolak jujur di server (§7) |

## Decision log terkait

Lihat `docs/DECISIONS.md` DEC-003 untuk kronologi lengkap kendala build-tooling sesi ini.
