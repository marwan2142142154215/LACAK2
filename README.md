# SMB — Device Management & Tracking Platform

Platform pengelolaan & pelacakan perangkat Android untuk perangkat **milik/dikelola secara sah** oleh organisasi (corporate-owned / BYOD dengan consent & kebijakan perusahaan). Lihat [docs/DECISIONS.md](docs/DECISIONS.md#dec-001) untuk baseline keamanan yang mengikat seluruh implementasi.

## Arsitektur

Lihat [docs/architecture.md](docs/architecture.md) untuk diagram lengkap. Ringkas:

```
Web/Master/Telegram -> Laravel API (smb-api) -> PostgreSQL + Redis -> AdonisJS Gateway (smb-gateway) -> WSS -> SMB Lacak (Android)
```

Semua traffic production lewat Cloudflare (`lacaksmbbot.com` + subdomain) via Cloudflare Tunnel dari server Windows lokal — tidak ada IP publik/port forwarding.

## Struktur repo

```
smb-platform/
├── smb-api/              Laravel — business logic, auth, RBAC, records
├── smb-gateway/          AdonisJS — WebSocket, realtime, command delivery
├── smb-web/               Vue + Tailwind — dashboard admin
├── smb-master-android/   Kotlin — app admin/operator (SMB Master)
├── smb-tracker-android/  Kotlin — managed-device agent (SMB Lacak)
├── smb-server-launcher/  Windows launcher/service orchestration
├── cloudflare/           Tunnel config & dokumentasi
├── docs/                 Dokumentasi teknis per topik
└── README.md
```

## Requirement versi (akan diisi lengkap di PHASE 3/7/10)

| Komponen | Versi |
|---|---|
| PHP | 8.3+ |
| Laravel | 11.x |
| Node.js | LTS (20.x) |
| AdonisJS | 6.x |
| Vue | 3.x |
| Kotlin | 1.9+ |
| Android minSdk / targetSdk / compileSdk | 26 / 36 / 36 |
| PostgreSQL | 17.x (Docker lokal, port 5433 — lihat `docker-compose.yml`) |
| Redis | 7.x (Docker lokal, port 6380) |
| AdonisJS | 6.18 (Node 24) |

## Status pembangunan (per PHASE §74)

- [x] PHASE 1 — Architecture (dokumen ini + `docs/architecture.md`, `docs/DECISIONS.md`)
- [x] PHASE 2 — Database (27 migration, lihat `docs/database.md`)
- [x] PHASE 3 — Laravel (scaffold, Docker infra lokal)
- [x] PHASE 4 — Authentication (API login, sessions, 10 test)
- [x] PHASE 5 — 2FA (16 test)
- [x] PHASE 6 — RBAC (Sites/Teams CRUD gated by permission, 24 test)
- [x] PHASE 7 — AdonisJS (scaffold, health check, internal auth middleware, 5 test)
- [x] PHASE 8 — WebSocket (auth handshake via `io.use()`, presence, device_sessions, 11 test — lihat `docs/websocket.md`)
- [x] PHASE 9 — Device registration (registration code generate+consume, race-safe row lock, 9 test — lihat `docs/api.md`)
- [x] PHASE 10 — Android SMB Lacak (kode lengkap: registration+WebSocket(Socket.IO)+foreground service+boot receiver+capability detection; **build/test fisik BELUM dijalankan sesi ini** — lihat `docs/android-compatibility.md` & DEC-003)
- [x] PHASE 11 — Heartbeat/reconnect (WS heartbeat jalur utama di AdonisJS, HTTPS fallback di Laravel, DeviceStatusResolver identik 2 sisi, WorkManager recovery Android — backend 7 test lolos, Android belum dijalankan fisik)
- [x] PHASE 12 — Command broker (create+dispatch+ack lifecycle, idempotency, anti wrong-device, forward-only state machine, auto-expire — 13 test baru, 66 test backend total lolos)
- [x] PHASE 13 — Lock/unlock (DeviceLockController, status LOCKED/restore otomatis, Android LockActivity Device Owner-aware)
- [x] PHASE 14 — OTP unlock self-service (hashed, single-use, attempt-limited, rate-limited, dispatch UNLOCK otomatis — 8 test baru)
- [x] PHASE 15 — Location (command LOCATION_REQUEST, hasil via ack.result ditulis AdonisJS, API history+latest, Android LocationCapability last-known jujur — 12 test baru)
- [x] PHASE 16 — Camera (upload URL via `MediaStorageService` — default storage LOKAL di perangkat/server pemilik produk sendiri, `SMB_MEDIA_DISK=spaces` opsional untuk DigitalOcean Spaces; 503 jujur kalau storage belum siap, device_media via ack.result, Android Camera2 + foreground service type camera — 105 test Laravel + 26 Adonis, lihat DEC-007)
- [x] PHASE 17 — Vue Dashboard (login+2FA, layout+RBAC nav, overview cards, device list+filter+detail dengan lock/unlock/request-lokasi+map Leaflet, Site & Tim CRUD — dicoba nyata di browser, bukan cuma build; menemukan & memperbaiki bug Sanctum CSRF nyata, lihat DEC-005/DEC-006)
- [x] PHASE 18 — Telegram Bot (webhook secret-verified, /start+approve-flow eksplisit admin, RBAC ditegakkan persis sama dengan dashboard, step-up confirm-before-execute untuk LOCK/UNLOCK/LOCATION_REQUEST, /camera jujur belum didukung — 18 test baru, dicoba nyata lewat webhook sungguhan di server dev + UI approve/revoke di browser)
- [x] PHASE 21 — Server Launcher Windows (`.exe` C#/.NET 8 asli, startup/health/shutdown teruji end-to-end di mesin dev — dikerjakan lebih awal atas permintaan eksplisit; lihat `smb-server-launcher/README.md`)
- [~] PHASE 19 — SMB Master (`smb-master-android`: login+2FA, device list+overview, command center lock/unlock/request-lokasi lewat endpoint admin yang sama dengan SMB Web; BLE proximity nyata — Master central/scanner, Lacak peripheral/advertiser, `docs/ble.md`. Kode lengkap & logic murni BLE sudah diuji JVM unit test; radio BLE fisik Master↔Lacak dan build/test Android fisik BELUM diverifikasi di sesi ini — lihat DEC-003/DEC-007)
- [~] PHASE 20 — Cloudflare (`cloudflare/config.yml` template + `cloudflare/README.md` langkah manual lengkap; `smb-server-launcher` sekarang mengecek status cloudflared struktural nyata — binary, config terisi, service Windows `RUNNING` — bukan placeholder `UNKNOWN` lagi, diverifikasi lewat `dotnet build` + `dotnet run -- doctor` sungguhan di sesi ini. **Menunggu Anda:** DNS/tunnel/WAF asli butuh akun Cloudflare Anda sendiri, tidak bisa diselesaikan dari sesi ini — lihat `cloudflare/README.md`)
- [ ] PHASE 22 — Testing
- [ ] PHASE 23 — Security hardening
- [ ] PHASE 24 — Production packaging

Setiap PHASE harus BUILD → TEST → VERIFY → DOCUMENT sebelum lanjut ke PHASE berikutnya (lihat §74/§81 spesifikasi asli). Tidak ada fase yang dinyatakan selesai tanpa itu.

## Development (lokal)

Prasyarat: PHP 8.3+, Composer, Node 24 LTS, Docker Desktop.

```bash
docker compose up -d
```

```bash
cd smb-api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8010
```

```bash
cd smb-gateway
npm install
node ace serve
```

> Port 8010 (bukan default 8000) sengaja — lihat `docs/DECISIONS.md` DEC-004: 8000 bentrok
> dengan service lain yang mungkin berjalan di 127.0.0.1 pada mesin dev.

Atau pakai launcher (PHASE 21) yang mengecek & menjalankan semuanya sekaligus:
```bash
cd smb-server-launcher
dotnet run
```
Mode cek-saja tanpa menjalankan apa pun (§77 SMB Doctor): `dotnet run -- doctor`.

Verifikasi: `curl http://127.0.0.1:8010/api/v1/health` dan `curl http://127.0.0.1:3334/health`.

Test:
```bash
cd smb-api && php artisan test
cd smb-gateway && node ace test
```

## Lisensi & kepatuhan

Sistem ini ditujukan untuk perangkat yang dikelola secara sah (corporate-owned/BYOD dengan consent eksplisit pengguna dan kebijakan perusahaan tertulis). Lihat [docs/DECISIONS.md](docs/DECISIONS.md) untuk batasan keamanan yang tidak dapat diubah tanpa tinjauan ulang.
