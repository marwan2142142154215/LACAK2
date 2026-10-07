# DECISIONS.md

Catatan keputusan arsitektur/teknis yang mengubah atau mengklarifikasi requirement awal, beserta alasan teknis/keamanannya. Setiap entri baru ditambahkan di atas (terbaru pertama).

---

## DEC-007 — SMB Master dibangun sebagai project Android independen; BLE identitas pakai ephemeral ID (belum GATT challenge/response)

**Tanggal:** 2026-10-07
**Fase:** PHASE 19
**Status:** Terbuka — radio BLE fisik & server storage lokal butuh tindak lanjut pemilik proyek.

### Konteks
PHASE 19 menambahkan `smb-master-android` (SMB Master, `com.smb.master`) dan fitur BLE proximity (§14-17 revisi prompt): Master sebagai BLE central/scanner, Lacak sebagai BLE peripheral/advertiser. Dua keputusan diambil:

1. **Project Android terpisah, bukan Gradle multi-module.** `smb-master-android` dan `smb-tracker-android` adalah dua aplikasi Android independen (applicationId berbeda, target pengguna berbeda — operator vs device terkelola) sesuai §81 project structure asli. Konstanta protokol BLE (`BleProximityProtocol`) karena itu DIDUPLIKASI secara sengaja di kedua project (didokumentasikan di `docs/ble.md`) — alternatif (Gradle module bersama) akan mengubah struktur project secara signifikan tanpa manfaat besar untuk 3 konstanta.
2. **Identitas BLE baseline = ephemeral identifier acak (16 byte, dirotasi 15 menit), BUKAN GATT challenge/response kriptografis.** §16 mewajibkan "tidak broadcast secret" (dipenuhi — ephemeral ID bukan secret, tidak bisa dipakai untuk impersonate device) dan HANYA menyebut challenge/response sebagai "jika melakukan connection-level verification" (kondisional, bukan wajib). Implementasi penuh butuh GATT server di Lacak + GATT client di Master + protokol kripto tambahan — scope signifikan di luar baseline proximity/discovery yang diminta. Didokumentasikan sebagai keterbatasan eksplisit di `docs/ble.md`, bukan diklaim sebagai "identitas terverifikasi".

### Keputusan tambahan — storage & server: perangkat milik pemilik produk, bukan cloud
Pemilik produk meminta server (smb-server-launcher) dan storage media berjalan di **perangkat miliknya sendiri**, bukan DigitalOcean Spaces/cloud pihak ketiga. Ini SELARAS dengan §110-114 revisi prompt (LOCAL STORAGE MODE sebagai default fase awal, storage abstraction `StorageService` dengan `LocalStorageDriver`) — PHASE 16 (Camera) yang sudah dibangun sebelumnya memakai presigned URL DigitalOcean Spaces secara langsung tanpa lapisan abstraksi; ini akan di-refactor jadi `LocalStorageDriver` sebagai default, `SpacesStorageDriver` tetap ada sebagai opsi masa depan (tidak dihapus, hanya bukan default).

### Dampak
- `docs/api.md` perlu update path baru (`/devices/{device}/media` upload lewat server lokal, bukan presigned Spaces URL) — dikerjakan sebagai follow-up langsung setelah entri ini.
- Tidak ada perubahan pada command broker/lock/unlock/location — hanya lapisan storage untuk camera/media yang terdampak.
- Radio BLE fisik (Master↔Lacak lewat Bluetooth sungguhan) dan build/test fisik Android (DEC-003) masih sama-sama menunggu verifikasi pemilik proyek di perangkat nyata — TIDAK diklaim "sudah bekerja" di sesi ini (§84/§88).

---

## DEC-005 — Sanctum `statefulApi()` dihapus dari `bootstrap/app.php`

**Tanggal:** 2026-10-07
**Fase:** PHASE 17 (Vue Dashboard)
**Status:** FINAL.

### Konteks
`bootstrap/app.php` memanggil `$middleware->statefulApi()` (bawaan scaffold Laravel). Ini membuat Sanctum menganggap request dari domain "stateful" (default termasuk `localhost` di PORT MANAPUN) sebagai first-party SPA cookie-based, dan mewajibkan CSRF token. Arsitektur kita BUKAN itu — dashboard Vue dan kedua app Android mengirim `Authorization: Bearer <token>` murni, tidak pernah cookie session, sejak PHASE 4/5 — dikonfirmasi oleh 78 test backend yang semuanya lewat tanpa CSRF sama sekali.

Bug ini **tidak pernah muncul di Pest** karena Pest tidak mengirim header `Origin`/`Referer`, jadi `EnsureFrontendRequestsAreStateful` tidak pernah menganggap request itu "dari frontend stateful". Begitu dashboard asli dibuka di browser (`http://localhost:5173`) dan login dicoba via `php artisan serve` beneran, request SUNGGUHAN membawa `Origin: http://localhost:5173` → host `localhost` cocok dengan daftar stateful default → Sanctum mewajibkan CSRF yang tidak pernah dikirim axios → `419 CSRF token mismatch`. Ditemukan lewat uji nyata di Browser pane (bukan asumsi), persis pola "no fake success" yang dipegang proyek ini — fitur baru WAJIB benar2 dicoba di browser sebelum dianggap selesai.

### Keputusan
Hapus `$middleware->statefulApi();` sepenuhnya. Semua autentikasi API tetap bearer-token Sanctum personal access token (`guard: sanctum`), tidak ada mode cookie-SPA sama sekali. Login dashboard dicoba ulang di browser setelah fix → sukses, redirect ke `/`, overview/device list/sites/teams semua memuat data asli dari API.

### Dampak
- Tidak ada perubahan pada 78 test backend (semuanya tetap hijau — mengonfirmasi mode ini memang tidak pernah dites/dipakai).
- Android & dashboard tidak perlu endpoint `/sanctum/csrf-cookie` atau `withCredentials` — axios client tetap sederhana (header `Authorization` saja).

---

## DEC-006 — Tabel device di dashboard TIDAK memakai `@tanstack/vue-table`

**Tanggal:** 2026-10-07
**Fase:** PHASE 17 (Vue Dashboard)
**Status:** FINAL (bisa direvisit kalau versi TanStack Table yang lebih stabil & terdokumentasi rilis).

### Konteks
`@tanstack/vue-table` yang terpasang (`^9.2.6`, rilis stabil terbaru di npm — bukan prerelease) adalah rewrite total dibanding v8: API berbasis "atom" reaktif (`useTable`, `TableFeatures`, `table.atoms`, `table.Subscribe`), bukan `useVueTable`/`getCoreRowModel`/`createColumnHelper` yang umum didokumentasikan. Dokumentasi publik untuk API baru ini masih sangat minim saat ditulis.

### Keputusan
`DevicesView.vue` merender tabel device dengan `v-for` Vue biasa (filter/search/pagination tetap ASLI lewat query params ke API — bukan mock), bukan lewat `@tanstack/vue-table`. Dependency tetap terpasang di `package.json` (sesuai stack wajib) untuk dipakai lagi kalau nanti dibutuhkan tabel dengan sorting/grouping kompleks dan API v9-nya sudah lebih jelas terdokumentasi, atau kalau proyek memutuskan pin ke v8 sebagai gantinya.

---

## DEC-004 — Port Laravel lokal dipindah ke 8010 (bukan 8000)

**Tanggal:** 2026-10-07
**Fase:** PHASE 21 (Server Launcher)
**Status:** FINAL.

### Konteks
Saat membangun & menguji `smb-server-launcher` (§77 SMB Doctor), health check terhadap `http://127.0.0.1:8000/api/v1/health` melaporkan "OK" — padahal Laravel milik proyek ini **tidak sedang dijalankan**. Diselidiki: port 8000 di mesin dev ini dipakai container Docker proyek lain (`apk-api-1`, sisa sesi sebelumnya, lihat percakapan awal soal container `apk-postgres-1` dkk.) yang **kebetulan** juga mengembalikan HTTP 200 dengan `"success":true` di endpoint health-nya sendiri — bentuk JSON beda, tapi status code sukses yang sama membuat pengecekan sederhana (`response.IsSuccessStatusCode`) salah mengenali itu sebagai Laravel kita.

Ini adalah **false-positive nyata yang ditemukan lewat testing sungguhan** (bukan ditebak) — persis kasus yang coding-standard §66 ("No Fake Implementation") ingin dicegah: men-declare sesuatu "OK" tanpa benar-benar memverifikasi identitasnya.

### Keputusan
Port dev lokal Laravel dipindah ke **8010** di semua tempat: `smb-api/.env` (`APP_URL`), instruksi `README.md`, `docs/api.md`, `smb-server-launcher/Program.cs`, dan `smb-tracker-android` (`BuildConfig.API_BASE_URL` via `10.0.2.2:8010`). Port 8001 juga terpakai proyek lain — 8010 dipilih sebagai port bebas yang terverifikasi kosong di mesin ini.

### Dampak
Tidak ada dampak pada logika aplikasi — murni perubahan konfigurasi port. Siapa pun yang men-deploy ke server produksi sendiri (bukan mesin dev ini) bebas memakai port lain/8000 asal tidak bentrok di lingkungan mereka; nilai di `.env` tetap bisa di-override.

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
