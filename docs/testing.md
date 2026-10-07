# Testing — status nyata per komponen (PHASE 22)

Dokumen ini adalah SATU sumber kebenaran untuk "apa yang benar-benar sudah dijalankan dan
lolos", dikumpulkan dari seluruh sesi pengembangan — bukan klaim, tapi hasil nyata yang
bisa diulang siapa pun dengan command yang dicatat di sini (§78 Definition of Done: "no
fake implementation", §84: jangan bilang "sudah bekerja" tanpa benar-benar diuji).

## Ringkasan

| Komponen | Framework | Jumlah test | Status | Command |
|---|---|---|---|---|
| `smb-api` (Laravel) | Pest | 105 passed, 320 assertions | ✅ Dijalankan nyata | `cd smb-api && vendor/bin/pest` |
| `smb-gateway` (AdonisJS) | Japa | 26 passed | ✅ Dijalankan nyata | `cd smb-gateway && node ace test` |
| `smb-web` (Vue) | Vitest | 13 passed | ✅ Dijalankan nyata | `cd smb-web && npm run test` |
| Logic BLE murni (`smb-master-android`) | JUnit4 (standalone JVM, di luar AGP) | 12 passed | ✅ Dijalankan nyata (lihat DEC-008) | — (diverifikasi manual sesi ini, bukan `./gradlew`) |
| Logic reconnect murni (`smb-tracker-android`) | JUnit4 (standalone JVM) | 3 passed | ✅ Dijalankan nyata (lihat DEC-008) | — |
| `smb-tracker-android` / `smb-master-android` (APK penuh, AGP) | Gradle + JUnit/instrumentation | — | ❌ BELUM bisa dijalankan sesi ini | Butuh Android SDK, diblokir egress `dl.google.com` (DEC-003/DEC-008) |
| `smb-server-launcher` (.NET) | — (tidak ada unit test otomatis, hanya `dotnet build` + `dotnet run -- doctor` manual) | — | ✅ `dotnet build` 0 error; `doctor` dijalankan nyata | `cd smb-server-launcher && dotnet build && dotnet run -- doctor` |
| `smb-gateway` load test (100 device bersamaan) | Skrip Node + `socket.io-client` asli (bukan k6/simulasi) | — | ✅ Dijalankan nyata, berulang | `scripts/load-test/README.md` |

**Total test otomatis yang benar-benar lolos sesi ini: 105 + 26 + 13 + 15 = 159.**

## Temuan performa + bug kompatibilitas nyata dari load test (PHASE 24)

Load test 100 device menemukan bug performa nyata di `smb-gateway`: `bcryptjs` (pure-JS)
membuat gateway nyaris berhenti total (35,4 detik untuk 99 koneksi, device lain yang
sudah online ikut telat dibalas sampai 34,8 detik) selama badai koneksi. Diganti ke
`bcrypt` native → 7,9 detik untuk waktu total yang sama (~4,5x lebih cepat), dan device
lain yang sudah online praktis TIDAK terganggu lagi (4-19ms, bukan 34,8 detik).

**Catatan jujur tambahan**: percobaan pertama swap ke `bcrypt` native terlihat "terlalu
bagus" (~130x, bukan ~4,5x) — ternyata itu karena `bcrypt` native v6 TIDAK mengenali
hash `$2y$` (format Laravel), sehingga 99 auth itu GAGAL SEMUA dengan cepat, bukan
berhasil dengan cepat. Ditemukan & diperbaiki (normalisasi `$2y$`→`$2b$`) SEBELUM commit,
dengan test regresi baru yang khusus memverifikasi hash format `$2y$` asli. Kronologi
lengkap: `docs/DECISIONS.md` DEC-009.

## Yang BELUM bisa diverifikasi, dan kenapa (jujur, §88)

1. **Build APK penuh + instrumented test** (`androidTest`, radio BLE fisik Master↔Lacak,
   Device Owner provisioning nyata) — butuh Android SDK (diblokir kebijakan egress
   `dl.google.com` di sesi ini, BUKAN bug — lihat DEC-008) DAN perangkat/emulator fisik
   dengan radio Bluetooth. **Tindak lanjut pemilik produk**: jalankan
   `./gradlew assembleDebug && ./gradlew testDebugUnitTest` di kedua project Android dari
   mesin Anda sendiri (yang punya Android Studio/SDK), lalu pasang ke 2 device fisik untuk
   uji BLE Master↔Lacak nyata.
2. **Load testing** (§41, simulasi 100–10.000 device bersamaan) — belum dijalankan.
   Lingkungan sesi ini tidak punya kapasitas infrastruktur (CPU/RAM/network) yang
   representatif untuk mengklaim hasil 10.000 koneksi WebSocket simultan apa pun —
   mengklaimnya tanpa server produksi sungguhan hanya akan jadi angka palsu (§66).
   **Tindak lanjut**: jalankan skrip load test (`scripts/load-test/README.md` — lihat
   PHASE 24) terhadap server staging/produksi Anda sendiri setelah PHASE 20 (Cloudflare)
   selesai dikonfigurasi dengan akun Anda.
3. **Cloudflare Tunnel konektivitas nyata** — butuh akun Cloudflare pemilik produk (lihat
   `cloudflare/README.md`). `smb-server-launcher` sudah bisa mendeteksi status
   struktural (binary/config/service Windows) tapi tidak konektivitas end-to-end.
4. **ESLint untuk `smb-web`** — belum dikonfigurasi (gap, dicatat untuk follow-up; tidak
   menghalangi test/build karena `vue-tsc` typecheck sudah jalan bersih).

## Cara menjalankan ulang semuanya (reproduksi)

```bash
# Backend Laravel
cd smb-api
cp .env.example .env && php artisan key:generate --force
# isi DB_* sesuai PostgreSQL Anda, lalu:
php artisan migrate --database=pgsql_testing --force
vendor/bin/pest

# Gateway AdonisJS (DB yang SAMA dengan Laravel di atas, §34)
cd ../smb-gateway
cp .env.example .env   # isi DB_DATABASE=smb_testing (atau DB sama yang sudah dimigrate)
npm install
node ace test

# Dashboard Vue
cd ../smb-web
npm install
npm run test

# Server launcher (.NET 8 SDK)
cd ../smb-server-launcher
dotnet build
dotnet run -- doctor
```
