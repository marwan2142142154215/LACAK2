# DECISIONS.md

Catatan keputusan arsitektur/teknis yang mengubah atau mengklarifikasi requirement awal, beserta alasan teknis/keamanannya. Setiap entri baru ditambahkan di atas (terbaru pertama).

---

## DEC-010 — Network Policy/IP Whitelist (§90-109) dibangun ulang di sesi ini setelah ditemukan pekerjaan lokal belum di-push di PC pemilik produk

**Tanggal:** 2026-10-07
**Fase:** PHASE 24 (tindak lanjut setelah setup Cloudflare Tunnel live)
**Status:** FINAL untuk baseline fitur — alerting Web/Master realtime (§104, push langsung ke UI) belum diimplementasikan, hanya Telegram.

### Konteks
Saat memverifikasi Cloudflare Tunnel yang baru disetup (DEC-007/PHASE 20) benar2 hidup, ditemukan: PC pemilik produk menjalankan repo di 3 folder terpisah dengan 3 remote GitHub BERBEDA (`LACAK1`/`LACAK2`/`LACAK3` — sisa sesi-sesi sebelumnya dengan tool berbeda), dan folder yang terhubung ke `LACAK2` (repo yang dikerjakan sesi ini) **7 commit ketinggalan** dari `origin/main` DAN punya pekerjaan lokal belum di-commit yang membangun fitur Network Policy/IP Whitelist (§90-109) + versi lain dari storage abstraction — keduanya tidak pernah di-push ke GitHub.

Pemilik produk sedang pergi sebentar saat ini ditemukan. Memaksa `git pull`/`git stash pop` di working tree yang sedang menjalankan server LIVE (sudah terbukti hidup lewat Cloudflare Tunnel, health check sukses) TANPA pemilik produk bisa mengawasi hasilnya berisiko merusak demo yang sedang berjalan kalau terjadi conflict (file PHP dengan conflict marker bisa menyebabkan 500 di request berikutnya). Alih-alih mencoba merekonsiliasi kode yang belum pernah dilihat isinya lewat relay command-line satu-satu (rawan salah, lambat, dan TIDAK bisa diverifikasi test di sesi ini karena berjalan di PC orang lain), fitur ini **dibangun ulang dari nol di sesi GitHub ini** — konsisten dengan kode yang sudah ada, teruji penuh, dan tidak menyentuh apa pun yang sedang berjalan di PC pemilik produk.

### Keputusan
1. Implementasi PHP (Laravel, `App\Services\NetworkPolicyEvaluator` + `NetworkViolationTracker`) DAN port TypeScript identik perilakunya (AdonisJS, `#services/network_policy_evaluator.ts` + `#services/network_violation_tracker.ts`) — dievaluasi di **kedua** jalur heartbeat (HTTPS fallback §45 DAN WebSocket jalur utama §6), bukan hanya satu, karena mayoritas device terhubung lewat WS.
2. IP client asli diambil dari header `CF-Connecting-IP` (Cloudflare edge) — `bootstrap/app.php` dikonfigurasi `trustProxies(at: ['127.0.0.1', '::1'])` karena aplikasi berjalan di belakang Cloudflare Tunnel lokal (§100) — tanpa ini `$request->ip()` akan selalu "127.0.0.1" untuk semua device, membuat whitelist IP tidak berguna.
3. Site tanpa policy terkonfigurasi = default-open (`ALLOWED`), bukan `BLOCKED` diam-diam (§66/§131 — restriksi harus eksplisit diatur admin).
4. Dedup pelanggaran via satu row "episode" per device (`resolved_at IS NULL` = masih terbuka) dengan row lock (`lockForUpdate`/`forUpdate`) untuk aman dari dua heartbeat (WS + HTTPS fallback) nyaris bersamaan — konsisten dengan pola idempotency command broker (§21-22, PHASE 12).
5. Alert Telegram (§104/§109) — ditambahkan Node-side `telegram_bot_client.ts` (port tipis dari `TelegramBotClient.php`) karena AdonisJS tidak pernah punya klien Telegram sendiri sebelumnya; env var `TELEGRAM_BOT_TOKEN` baru di `smb-gateway/.env`, opsional (skip jujur kalau kosong, §69).
6. Push realtime ke Web Dashboard/SMB Master (§104, bukan cuma Telegram) **belum diimplementasikan** — dicatat sebagai keterbatasan, bukan diklaim ada. Monitor endpoint (`GET /network-violations`) sudah cukup untuk polling manual oleh FE yang belum dibangun.

### Hasil terverifikasi
- Laravel: 18 test baru (7 evaluator + 6 violation/dedup/alert + 5 CRUD policy), total 123/123 lolos.
- AdonisJS: 2 test baru (default-open, dedup BLOCKED lewat WS), total 29/29 lolos.
- `tsc --noEmit` + `eslint` bersih di kedua sisi.

### Tindak lanjut untuk pemilik produk
Folder lokal "apk claude" (`C:\Users\ACE COMPUTER\Documents\apk claude`) masih berisi pekerjaan belum di-commit (lihat riwayat chat sesi ini untuk daftar file lengkap). **Aman** untuk `git stash` lalu `git pull origin main` di folder itu kapan pun siap (server yang sedang jalan tetap aman — proses PHP yang sudah berjalan tidak otomatis reload source sampai request berikutnya/restart). Pekerjaan storage abstraction lokal yang lama BISA dibuang (sudah digantikan versi yang lebih lengkap dari sesi ini, DEC-007) — tapi backup dulu (`git stash` TIDAK menghapus, hanya menyimpan) sebelum memutuskan.

---

## DEC-009 — `smb-gateway`: ganti `bcryptjs` → `bcrypt` native (ditemukan lewat load test PHASE 24, PLUS bug kompatibilitas `$2y$` yang ketemu & diperbaiki sebelum sempat jadi regresi produksi)

**Tanggal:** 2026-10-07
**Fase:** PHASE 24 (load testing, §41/§42)
**Status:** FINAL — diverifikasi berulang di lingkungan bersih, termasuk verifikasi bahwa auth benar2 SUKSES (bukan gagal cepat).

### Konteks
Load test 100 device bersamaan (`scripts/load-test/`) awalnya menemukan `smb-gateway`
butuh **35,4 detik** untuk menyelesaikan 99 koneksi WebSocket baru, dan device LAIN yang
sudah online ikut tidak dibalas heartbeat-nya sampai **34,8 detik** — gateway nyaris
berhenti total selama badai koneksi, memakai `bcryptjs` (pure-JS). Isolated benchmark
(`node -e`, tanpa gateway sama sekali) mengonfirmasi: `bcryptjs.compare()` di cost factor
12 (format hash yang diterbitkan Laravel) untuk 20 pemanggilan **bersamaan** makan
**7,3 detik** — hampir identik dengan 20 × waktu satu compare, membuktikan **tidak ada
paralelisme nyata** walau kodenya memakai `await`/bentuk "async" bcryptjs.

### Dua kesalahan yang ditemukan DAN diperbaiki SELAMA proses verifikasi (dicatat jujur, bukan disembunyikan)

**1. Pengukuran awal tercemar proses nyasar.** Perbandingan pertama `bcryptjs` vs
`bcrypt` native SAMA-SAMA menunjukkan ~35 detik, seolah fix tidak berpengaruh — sampai
ketahuan ada beberapa proses `node ace test`/`node ace serve` LAMA dari pengujian
sebelumnya di sesi ini (plus Gradle/Kotlin daemon sisa verifikasi BLE PHASE 19) masih
hidup di background, ikut menyedot keempat core CPU container ini. `pkill -f <pattern>`
di lingkungan ini **berulang kali tidak benar2 mematikan proses** pada percobaan
pertama (exit code terlihat sukses, tapi `ps aux` ulang masih menunjukkan proses yang
sama) — pelajarannya: SELALU verifikasi `ps aux` benar2 kosong (bukan percaya exit code)
sebelum mengukur performa apa pun di container terbatas seperti ini.

**2. Setelah lingkungan bersih, `bcrypt` native v6 TERNYATA tidak mengenali prefix `$2y$`**
(format yang dipakai Laravel/PHP — HANYA `$2a$`/`$2b$` yang dikenali). Login dengan secret
BENAR terhadap hash `$2y$` ASLI dari Laravel tetap mengembalikan `false` dari
`bcrypt.compare()` Node, walau PHP `Hash::check()` bilang `true` untuk pasangan secret+hash
yang SAMA PERSIS — dibuktikan langsung lewat test manual, bukan diasumsikan. **Ini berarti
angka "~130x lebih cepat" yang sempat tercatat di draft awal entri ini SALAH** — yang
sebenarnya terjadi adalah `bcrypt.compare()` GAGAL CEPAT karena format hash tidak dikenali
(bukan berhasil cepat), jadi test awal (289ms/238ms) itu diam-diam mengukur 99 AUTH YANG
GAGAL SEMUA, bukan 99 auth yang berhasil. Kalau fix ini sampai di-deploy TANPA ketahuan,
akibatnya: **semua device gagal connect ke gateway setelah deploy** — regresi kritis.
Ditemukan sebelum commit lewat kebiasaan "verifikasi hasil positif sebelum percaya",
bukan kebetulan.

### Keputusan
1. Ganti `bcryptjs` → `bcrypt` (native, package `bcrypt@^6`, prebuilt binding — tidak
   perlu compiler manual) di `app/services/websocket_service.ts`.
2. **WAJIB** disertai `normalizeBcryptHashForNode()` — substitusi prefix `$2y$` → `$2b$`
   sebelum `bcrypt.compare()` dipanggil. `$2y$`/`$2b$` adalah varian penanda versi yang
   identik secara kriptografis (bukan algoritma berbeda) — substitusi ini standar &
   aman untuk interop PHP↔Node, BUKAN downgrade keamanan. Tidak ada migrasi data; hash
   yang tersimpan di DB tidak diubah, hanya dinormalisasi saat dibaca untuk verifikasi.
3. Test regresi baru ditambahkan khusus untuk ini: `websocket.spec.ts` sekarang punya
   test case yang membuat kredensial dengan prefix `$2y$` eksplisit (bukan `$2b$` bawaan
   `bcrypt.hash()` Node) — tanpa test ini, blind spot ini bisa muncul lagi kalau library
   hashing diganti lagi di masa depan tanpa ada yang sadar test lama tidak pernah
   menguji hash format Laravel yang sesungguhnya.

### Hasil AKHIR yang terverifikasi benar (99/99 auth SUKSES, dicek eksplisit — bukan gagal cepat)

| | `bcryptjs` (lama) | `bcrypt` native + fix `$2y$` (baru) |
|---|---|---|
| 99 koneksi baru bersamaan, semua berhasil | 35,4 detik | **7,9 detik** |
| Heartbeat device LAIN (sudah online) selama badai | delay sampai 34,8 detik | **4–19 ms** (praktis tidak terganggu) |

**~4,5x lebih cepat** untuk waktu total badai 99 koneksi (bukan ~130x seperti draft awal
yang salah) — **DAN** yang lebih penting: device yang sudah online **tidak lagi ikut
macet** selama badai auth berlangsung (perbedaan 34,8 detik → single-digit milidetik ini
yang paling berharga untuk skenario reconnect-storm §42, bukan angka agregat). Diukur
berulang (3x) dengan hasil konsisten (7,9s / 7,9s, 99/99 sukses setiap kali). Lihat
`scripts/load-test/README.md` untuk cara mereproduksi, dan `docs/testing.md` untuk
ringkasan status test keseluruhan.

### Dampak
- `smb-gateway/package.json`: `bcryptjs` dihapus, `bcrypt` + `@types/bcrypt` ditambahkan.
- 27 test AdonisJS lolos (26 lama + 1 test regresi `$2y$` baru) — perilaku functional
  identik untuk hash `$2b$`, DAN sekarang juga benar untuk hash `$2y$` asli Laravel.
- Tidak ada perubahan skema/migration — murni perbaikan performa + kompatibilitas library.
- Skala yang diverifikasi: 100 device (bukan 1.000-10.000, §41) — keterbatasan container
  dev sesi ini (4 core CPU), bukan klaim bahwa 10.000 device pasti aman di skala itu.
  Lihat "Keterbatasan" di `scripts/load-test/README.md` untuk tindak lanjut pemilik produk.
- **Pelajaran proses untuk sesi berikutnya**: angka performa yang "terlalu bagus untuk
  benar" (130x dari satu swap library) seharusnya memicu kecurigaan lebih awal — jeda
  untuk memverifikasi BAHWA hasilnya benar2 sukses (bukan cuma cepat) sebelum menulis
  laporan, bukan sesudahnya.

---

## DEC-008 — Logic BLE/reconnect murni diverifikasi NYATA (compile+run di luar Gradle/AGP); build APK penuh tetap terblokir (alasan baru, bukan DEC-003 lama)

**Tanggal:** 2026-10-07
**Fase:** PHASE 19 (tindak lanjut)
**Status:** FINAL untuk sesi ini — build APK penuh butuh mesin dengan akses `dl.google.com` (mesin pemilik produk).

### Konteks
Sesi ini mencoba benar-benar memverifikasi kode Android (bukan cuma menulisnya), per permintaan eksplisit pemilik produk. Diselidiki:

1. Tidak ada Android SDK terpasang di container sesi ini (`$ANDROID_HOME` kosong, tidak ada `android.jar` di mana pun di filesystem).
2. Mencoba memasang SDK lewat `sdkmanager`/`dl.google.com` → **ditolak kebijakan egress organisasi** (`403` dari proxy sesi, bukan error jaringan transien — dikonfirmasi lewat `curl` langsung ke `dl.google.com`, dan proxy README eksplisit bilang "do not retry or route around it" untuk 403/407 kebijakan). Ini BUKAN masalah yang bisa diperbaiki dari dalam sesi — Android SDK hanya didistribusikan oleh Google lewat domain itu, tidak ada mirror resmi di Maven Central/registry lain yang di-allowlist proxy ini.
3. Karena itu, `./gradlew assembleDebug` dan `./gradlew testDebugUnitTest` (yang butuh `android.jar` dari `compileSdk`, dipakai AGP bahkan untuk unit test JVM di modul `app`) **tetap tidak bisa dijalankan** di sesi ini — DEC-003 masih berlaku, dengan akar masalah yang berbeda dari sesi sebelumnya (dulu: loopback TCP Gradle di Windows; sekarang: kebijakan egress jaringan Linux container).

### Apa yang BENAR-BENAR berhasil diverifikasi (bukan klaim kosong)
Logic murni (tanpa dependency `android.*` apa pun) di-copy ke project Gradle JVM standalone terpisah (`org.jetbrains.kotlin.jvm`, bukan AGP, memakai JUnit4 dari Maven Central — yang TIDAK diblokir proxy) dan benar-benar di-compile + dijalankan:

- `RssiSmoother.kt` + `RssiSmootherTest.kt` (smb-master-android) — 5 test PASSED
- `ProximityClassifier.kt` + `ProximityClassifierTest.kt` (smb-master-android) — 7 test PASSED
- `DeviceReconnectPolicy.kt` + `DeviceReconnectPolicyTest.kt` (smb-tracker-android) — 3 test PASSED

Total **15/15 PASSED**, nyata dijalankan, bukan dilaporkan begitu saja. Proyek verifikasi ini dibuat di `/tmp` (scratch, di luar repo) dan dihapus setelah selesai — tidak masuk git karena bukan bagian arsitektur produk, murni alat verifikasi sesi ini.

### Yang TIDAK bisa diverifikasi di sesi ini (dan kenapa)
- Compile penuh modul `app` (kode yang memang memakai `android.bluetooth.le.*`, `android.hardware.camera2.*`, dll) — butuh `android.jar`, terblokir egress.
- BLE radio fisik Master↔Lacak, instrumented test (`androidTest`), instalasi APK ke device/emulator — butuh hardware fisik atau emulator dengan SDK, keduanya tidak tersedia di container ini.

### Rekomendasi ke pemilik produk
Jalankan `./gradlew assembleDebug && ./gradlew testDebugUnitTest` untuk `smb-master-android` DAN `smb-tracker-android` di mesin/laptop Anda sendiri (yang punya akses internet biasa ke `dl.google.com` dan Android Studio/SDK terpasang) — sejalan dengan keputusan "server & storage pakai perangkat Anda sendiri" (lihat DEC-007). Kalau ada compile error nyata yang muncul di sana, laporkan baris errornya — saya bisa perbaiki tanpa perlu menjalankan build itu sendiri, selama errornya ditunjukkan verbatim.

---

## DEC-007 — SMB Master dibangun sebagai project Android independen; BLE identitas pakai ephemeral ID (belum GATT challenge/response)

**Tanggal:** 2026-10-07
**Fase:** PHASE 19
**Status:** Sebagian selesai — BLE/SMB Master selesai dikerjakan (kode lengkap, radio fisik belum diverifikasi); storage lokal SUDAH diimplementasikan & diuji (lihat "Dampak" di bawah).

### Konteks
PHASE 19 menambahkan `smb-master-android` (SMB Master, `com.smb.master`) dan fitur BLE proximity (§14-17 revisi prompt): Master sebagai BLE central/scanner, Lacak sebagai BLE peripheral/advertiser. Dua keputusan diambil:

1. **Project Android terpisah, bukan Gradle multi-module.** `smb-master-android` dan `smb-tracker-android` adalah dua aplikasi Android independen (applicationId berbeda, target pengguna berbeda — operator vs device terkelola) sesuai §81 project structure asli. Konstanta protokol BLE (`BleProximityProtocol`) karena itu DIDUPLIKASI secara sengaja di kedua project (didokumentasikan di `docs/ble.md`) — alternatif (Gradle module bersama) akan mengubah struktur project secara signifikan tanpa manfaat besar untuk 3 konstanta.
2. **Identitas BLE baseline = ephemeral identifier acak (16 byte, dirotasi 15 menit), BUKAN GATT challenge/response kriptografis.** §16 mewajibkan "tidak broadcast secret" (dipenuhi — ephemeral ID bukan secret, tidak bisa dipakai untuk impersonate device) dan HANYA menyebut challenge/response sebagai "jika melakukan connection-level verification" (kondisional, bukan wajib). Implementasi penuh butuh GATT server di Lacak + GATT client di Master + protokol kripto tambahan — scope signifikan di luar baseline proximity/discovery yang diminta. Didokumentasikan sebagai keterbatasan eksplisit di `docs/ble.md`, bukan diklaim sebagai "identitas terverifikasi".

### Keputusan tambahan — storage & server: perangkat milik pemilik produk, bukan cloud
Pemilik produk meminta server (smb-server-launcher) dan storage media berjalan di **perangkat miliknya sendiri**, bukan DigitalOcean Spaces/cloud pihak ketiga. Ini SELARAS dengan §110-114 revisi prompt (LOCAL STORAGE MODE sebagai default fase awal). Diimplementasikan sebagai `App\Services\MediaStorageService` — SATU class abstraksi dengan method `uploadTarget()`/`readUrl()` yang bercabang berdasarkan `config('filesystems.default_media_disk')` (bukan dua class driver terpisah `LocalStorageDriver`/`SpacesStorageDriver` seperti rencana awal di §110 — disederhanakan karena hanya 2 mode dan logikanya pendek, §59 jangan over-engineer untuk kebutuhan sekecil ini):

- **Default (`SMB_MEDIA_DISK=smb_media`, lokal):** disk `smb_media` (local driver, root `SMB_STORAGE_PATH`, §111). Upload device memakai **Laravel signed route** (`URL::temporarySignedRoute`) ke endpoint baru `PUT /api/v1/devices/media/upload` (bukan S3 presigned PUT) — properti keamanan setara (time-limited + HMAC signature, bukan "tanpa auth"), device tetap upload langsung ke server tanpa lewat business logic tambahan. Baca balik lewat `GET /api/v1/devices/media/download` (signed juga). Kedua endpoint divalidasi `assertSafePath()` (prefix `devices/`, anti path-traversal, §113).
- **Opsional (`SMB_MEDIA_DISK=spaces`):** kode PHASE 16 asli (presigned S3 URL ke Spaces) tetap ada, dipilih lewat `.env` tanpa ubah kode.

### Dampak
- `docs/api.md`, `docs/architecture.md`, `docs/database.md` diupdate untuk mendokumentasikan endpoint & disk baru.
- `DeviceCameraController`, `DeviceMedia::signedUrl()` diubah untuk delegasi ke `MediaStorageService` — tidak ada perubahan pada command broker/lock/unlock/location.
- Test backend diupdate (`DeviceCameraTest.php`) + ditambah (`DeviceMediaTransferTest.php`, 8 test baru: roundtrip upload→download bytes identik, signature tampered→403, expired→403, path traversal→400, path di luar prefix `devices/`→400, oversize→422, download 404 kalau file belum ada).
- Radio BLE fisik (Master↔Lacak lewat Bluetooth sungguhan) dan build/test fisik Android (DEC-003) masih menunggu verifikasi pemilik proyek di perangkat nyata — TIDAK diklaim "sudah bekerja" (§84/§88). Ini TIDAK terpengaruh oleh perubahan storage di atas (keduanya independen).

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
