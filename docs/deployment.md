# Deployment — checklist produksi (PHASE 24)

Target: men-deploy SMB ke **perangkat/server milik pemilik produk sendiri** (bukan cloud
pihak ketiga — lihat `docs/DECISIONS.md` DEC-007), dengan domain `lacaksmbbot.com` di
depan Cloudflare Tunnel (DEC-007, PHASE 20).

## 1. Versi yang dipakai (pin, §58)

| Komponen | Versi | Sumber kebenaran |
|---|---|---|
| PHP | 8.3+ | `smb-api/composer.json` |
| Laravel | 11.x (terverifikasi `v13.35.0` di sesi ini — composer selalu ambil versi stabil terbaru yang kompatibel, cek `composer show laravel/framework`) | `smb-api/composer.lock` |
| Node.js | LTS (v22/v24) | `smb-gateway/package.json` engines, `smb-web/package.json` |
| AdonisJS | 6.18 | `smb-gateway/package.json` |
| Vue | ^3.5 | `smb-web/package.json` |
| PostgreSQL | 17.x (Docker lokal via `docker-compose.yml`) | — |
| Redis | 7.x (Docker lokal) | — |
| .NET | 8 SDK/Runtime | `smb-server-launcher/SmbServerLauncher.csproj` |
| Kotlin | 2.2.20 | `smb-tracker-android/gradle/libs.versions.toml`, `smb-master-android/gradle/libs.versions.toml` |
| Android minSdk/target/compile | 26 / 36 / 36 | kedua `app/build.gradle.kts` |

Jangan menaikkan versi minSdk/targetSdk/compileSdk Android tanpa persetujuan eksplisit
(§38, `docs/android-compatibility.md`).

## 2. Urutan deploy (sesuai §65/§69 startup order)

```
1. PostgreSQL (docker compose up -d, atau instalasi native)
2. Redis      (docker compose up -d, atau instalasi native)
3. Migration  (cd smb-api && php artisan migrate --force)
4. Laravel    (smb-api, port 8010)
5. AdonisJS   (smb-gateway, port 3334)
6. smb-web    (build produksi: npm run build, hasil di smb-web/dist, di-serve statis)
7. Telegram webhook (daftarkan URL webhook ke Bot API Telegram — lihat smb-api .env TELEGRAM_*)
8. Cloudflare Tunnel (cloudflared service — lihat cloudflare/README.md, PHASE 20)
9. Health verification (smb-server-launcher doctor, atau GET /api/v1/health)
```

Gunakan `smb-server-launcher` (PHASE 21) untuk menjalankan langkah 3-5 + verifikasi 9
sekaligus dari satu console — lihat `smb-server-launcher/README.md`.

## 3. Environment (§57 — jangan campur LOCAL/STAGING/PRODUCTION)

- `smb-api/.env`, `smb-gateway/.env`: **tidak pernah** di-commit (sudah di `.gitignore`).
  Salin dari `.env.example` masing-masing, isi credential ASLI hanya di server produksi.
- `APP_KEY` (Laravel) dan `APP_KEY`/`DEVICE_TOKEN_SECRET`/`GATEWAY_INTERNAL_SECRET`
  (AdonisJS) **WAJIB** nilai acak unik per environment — JANGAN reuse nilai dari
  development.
- `SMB_STORAGE_PATH` (§110-114): arahkan ke disk dengan kapasitas cukup untuk foto device
  (bukan default `storage/app/smb-media` di dalam folder aplikasi kalau volumenya besar).

## 4. Storage & backup (§45)

Storage media default LOKAL (`SMB_MEDIA_DISK=smb_media`, lihat DEC-007) — artinya
**backup folder `SMB_STORAGE_PATH` adalah tanggung jawab Anda**, tidak otomatis
ter-replikasi seperti object storage cloud. Rekomendasi minimal:

```bash
# Backup PostgreSQL (jalankan terjadwal, misal cron/Task Scheduler harian)
pg_dump -U <user> -h <host> -p <port> <database> > backup_$(date +%Y%m%d).sql

# Restore (TES ini sebelum butuh beneran — §85 "restore test" bagian dari production gate)
psql -U <user> -h <host> -p <port> <database> < backup_20261007.sql

# Backup folder media lokal — robocopy (Windows) atau rsync, ke disk/lokasi terpisah.
```

**Belum diuji di sesi ini**: restore test end-to-end (§85 production gate meminta ini
sebelum release) — butuh instance PostgreSQL produksi/staging nyata untuk dicoba, bukan
sesuatu yang bisa "dianggap pasti bekerja" dari `pg_dump`/`psql` saja tanpa dicoba.
**Tindak lanjut pemilik produk**: jalankan backup lalu restore ke database KOSONG di
mesin terpisah, verifikasi row count cocok, sebelum pertama kali go-live.

## 5. APK release (§6, §115)

```bash
# Debug (testing)
cd smb-tracker-android  # atau smb-master-android
./gradlew assembleDebug

# Release (production) — minifyEnabled saat ini FALSE di app/build.gradle.kts (lihat
# komentar di file itu) — aktifkan + tambahkan aturan ProGuard/R8 kalau APK release
# pertama akan didistribusikan ke device produksi sungguhan.
./gradlew assembleRelease
```

Signing keystore **TIDAK** disertakan di repo (§6) — buat sendiri dengan `keytool`, simpan
di luar Git, backup di tempat aman (hilang = tidak bisa update APK yang sudah terpasang
di device tanpa uninstall).

## 6. Production health gate (§85, sebelum go-live)

Checklist minimal yang WAJIB lolos sebelum dianggap siap produksi — status nyata per item
per sesi ini:

- [x] Backend build + unit/feature test (105 Laravel + 26 Adonis + 13 Vitest = 144, lihat `docs/testing.md`)
- [x] Logic Android murni (BLE/reconnect) ter-compile+run (15 test, lihat DEC-008)
- [ ] Build APK penuh + instrumented test — **butuh mesin Anda sendiri** (DEC-008)
- [ ] BLE radio fisik Master↔Lacak — **butuh 2 device fisik**
- [ ] Load test skala realistis (100-10.000 device) — **butuh server staging/produksi**
- [ ] Database backup+restore dicoba nyata — **butuh instance terpisah**
- [ ] Cloudflare Tunnel+DNS+WAF aktif — **butuh akun Cloudflare Anda** (`cloudflare/README.md`)
- [ ] `smb-server-launcher.exe` dijalankan nyata di Windows asli (bukan `dotnet run` di Linux container, lihat `smb-server-launcher/README.md`)

Item yang masih `[ ]` BUKAN berarti "gagal" — artinya butuh akses yang hanya pemilik
produk punya (hardware, akun cloud, mesin Windows). Jangan menganggap SMB "siap
produksi" sampai semua baris di atas benar-benar `[x]` oleh seseorang yang benar-benar
menjalankannya (§84/§85 — DO NOT RELEASE kalau ada critical failure yang belum
diverifikasi).
