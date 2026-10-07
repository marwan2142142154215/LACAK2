# DECISIONS.md

Catatan keputusan arsitektur/teknis yang mengubah atau mengklarifikasi requirement awal, beserta alasan teknis/keamanannya. Setiap entri baru ditambahkan di atas (terbaru pertama).

---

## DEC-003 — Android (PHASE 10): kode ditulis lengkap, build/test fisik belum dijalankan sesi ini

**Tanggal:** 2026-10-07
**Fase:** PHASE 10
**Status:** Terbuka — butuh tindak lanjut pemilik proyek.

### Kontex
Mesin ini punya JDK 17, Android SDK (platform 35/36/37, build-tools, emulator `lacak-api35`/`lacak-api36`) dari sesi sebelumnya. Namun **Gradle tidak bisa dijalankan dari tool otomasi (PowerShell sandbox) sesi ini** — diverifikasi konkret:
- `gradle --version` berhasil (tidak butuh fork proses)
- `gradle wrapper` / task build apa pun **selalu gagal**: `java.io.IOException: Unable to establish loopback connection` → `SocketException: Invalid argument: connect`
- Dibuktikan bahwa loopback TCP **dalam satu proses** tetap berfungsi (test `.NET TcpListener`/`TcpClient` dan Java `ServerSocket`/`Socket` murni berhasil)
- Loopback **antar-proses** (gradle.bat → daemon/single-use fork java.exe terpisah) gagal konsisten, di semua kombinasi: dengan/tanpa daemon, 2 versi Gradle berbeda (8.14.3 & 9.3.0), `dangerouslyDisableSandbox: true`, env var JVM args berbeda, proses lama di-kill dulu
- Tool terminal asli (`mcp__terminal`) juga gagal dipakai sebagai alternatif karena script integrasi shell-nya hilang di mesin ini (`claude-desktop.ps1` tidak ditemukan) — masalah terpisah, bukan Gradle

### Keputusan
1. Seluruh kode Android (Gradle config, Kotlin source, resource, test) ditulis **lengkap dan sesuai requirement** (minSdk 26, targetSdk/compileSdk 36), termasuk mengadaptasi & memverifikasi-ulang arsitektur dari proyek sisa sesi sebelumnya di `C:\Users\ACE COMPUTER\Documents\apk\smb-tracker-android` (ditemukan sudah ada, dibangun sesi lain untuk spek yang sama — direview dan di-ADAPTASI, bukan di-copy-paste buta, karena kontrak APInya tidak sinkron dengan backend yang sudah saya bangun).
2. API library pihak ketiga (`socket.io-client`, `engine.io-client`) **diverifikasi lewat inspeksi bytecode jar asli** (`javap`) karena tidak bisa diverifikasi lewat compile — ditemukan 2 isu nyata lewat cara ini: (a) transitive `org.json:json` yang bentrok dengan platform Android → di-exclude, (b) transitive OkHttp 3.12.12 vs OkHttp 4.12.0 kita → aman via Gradle version resolution, didokumentasikan di komentar `app/build.gradle.kts`.
3. **Tidak ada klaim "BUILD PASS" atau "TEST PASS"** untuk Android di sesi ini (§80 Definition of Done) — status jujur didokumentasikan di `docs/android-compatibility.md`.
4. Pemilik proyek perlu menjalankan `./gradlew assembleDebug && ./gradlew testDebugUnitTest` sendiri di terminal biasa (bukan lewat sesi agent ini) untuk verifikasi final, memakai emulator `lacak-api35`/`lacak-api36` yang sudah tersedia.

### Dampak
PHASE 10 secara kode selesai sesuai scope (registration + WebSocket connect + foreground service + boot receiver + capability detection). Heartbeat (PHASE 11) sengaja TIDAK disertakan di PHASE 10 karena endpoint Laravel-nya belum ada — menghindari menulis kode client terhadap kontrak server yang belum nyata (§66).

---

## DEC-002 — Branch tunggal `main` (develop dihapus)

**Tanggal:** 2026-10-07
**Fase:** PHASE 7
**Status:** FINAL — atas permintaan eksplisit pemilik produk.

### Konteks
§73 spesifikasi asli mewajibkan struktur branch `main` / `develop` / `feature/*` / `fix/*` / `hotfix/*` dengan larangan direct push ke `main`. Pemilik produk meminta konsolidasi: pindahkan semua ke `main`, hapus `develop`, untuk menyederhanakan workflow solo-development saat ini.

### Keputusan
Repo sekarang hanya memakai branch `main`. Commit langsung ke `main` diperbolehkan sampai ada kebutuhan nyata untuk kolaborasi multi-kontributor (branch protection/PR review) — saat itu terjadi, `develop` + `feature/*` dapat dihidupkan kembali tanpa kehilangan histori apa pun (semua commit PHASE 1-6 tetap ada di `main`).

### Dampak
Tidak ada dampak pada kode/skema — ini murni keputusan workflow Git, tidak mengubah requirement teknis lain.

---

## DEC-001 — Penolakan mekanisme stealth/exploit; baseline Device Owner berbasis consent

**Tanggal:** 2026-10-07
**Fase:** PHASE 1 (Architecture)
**Status:** FINAL — tidak dapat diubah tanpa persetujuan eksplisit pemilik produk dan tinjauan ulang keamanan.

### Konteks

Spesifikasi awal proyek berisi satu paragraf yang meminta pembangunan mekanisme:
"mencuri credential, bypass permission, bypass Android security, stealth surveillance, hidden camera exploit, root exploit, credential interception, security evasion."

Paragraf ini **bertentangan langsung** dengan >15 bagian lain pada spesifikasi yang sama (§7, §16, §27, §33, §69, §78, §79) yang secara eksplisit melarang hal yang sama: *"Jangan menggunakan accessibility abuse, exploit, hidden privilege escalation, root exploit, permission bypass, security bypass, hidden camera exploit, stealth surveillance mechanism."*

Pada klarifikasi lanjutan, muncul permintaan agar agen tidak menggunakan Android Enterprise **Device Owner** karena "nanti bisa di-uninstall", dan diminta solusi yang "simpel dan powerful" — pola ini konsisten dengan karakteristik stalkerware (kontrol jarak jauh + anti-uninstall pada perangkat yang penggunanya tidak diberi kendali penuh).

### Keputusan

1. **Baseline yang dipakai adalah larangan di §7/§16/§27/§69/§78/§79**, bukan paragraf yang memintanya. Semua kapabilitas kontrol perangkat (lock, unlock, camera capture, location) **hanya** diimplementasikan melalui Android API resmi dan Android Enterprise / Device Owner API.
2. **Use case yang disetujui pemilik produk:** perangkat milik perusahaan/organisasi, dioperasikan untuk karyawan di bawah kebijakan perusahaan yang jelas (BYOD/corporate-owned, dengan consent/kebijakan tertulis). Ini adalah use case MDM standar industri (sebanding dengan Microsoft Intune, Google Workspace Endpoint Management, dll).
3. **Uninstall-protection** hanya didapat secara sah melalui Device Owner enrollment (`setDeviceOwner` / zero-touch / QR provisioning saat setup awal perangkat). Ini bukan "kerumitan yang harus dihindari" — ini adalah satu-satunya mekanisme yang membuat uninstall-protection *legal dan bukan malware*, karena perangkat secara eksplisit di-enroll sebagai milik organisasi sebelum digunakan karyawan.
4. **Mode non-Device-Owner tetap didukung** sebagai fallback yang jujur: aplikasi berjalan sebagai app biasa dengan permission runtime standar, dan secara jujur melaporkan ke dashboard bahwa device **tidak terkelola** (`managed_device: false`) sehingga fitur lock/camera-policy-enforced tidak tersedia untuk device tersebut. Tidak ada fitur yang "dipalsukan berhasil" ketika capability tidak ada (lihat §66, §69, §70).
5. Tidak ada credential interception, bypass permission, root exploit, atau stealth surveillance yang diimplementasikan di bagian mana pun dari sistem ini.

### Alasan teknis

- Device Owner API (`DevicePolicyManager`, Android Enterprise) adalah mekanisme **resmi** Google untuk kontrol perangkat terkelola — termasuk lock task mode, kebijakan kamera, dan pencegahan uninstall oleh end-user, tanpa root/exploit.
- Pendekatan non-consensual (stealth, anti-uninstall tanpa enrollment resmi) tidak hanya melanggar kebijakan keamanan proyek ini, tetapi juga Google Play Policy, dan di banyak jurisdiksi berpotensi melanggar hukum (UU ITE / computer misuse law) jika diterapkan pada perangkat yang bukan milik sah operator atau tanpa consent penggunanya.

### Dampak pada requirement lain

- §16 (SMB Lacak) tetap mengimplementasikan `managed_device` dan `device_owner` sebagai field capability report (§67), dilaporkan jujur.
- §23 (Device Lock) tetap memakai Lock Task Mode / Device Owner API sesuai spesifikasi asli.
- Provisioning flow Device Owner akan didokumentasikan di `docs/android-compatibility.md` dan `docs/deployment.md` pada PHASE 10.

---
