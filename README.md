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
| PostgreSQL | 16.x |
| Redis | 7.x |

## Status pembangunan (per PHASE §74)

- [x] PHASE 1 — Architecture (dokumen ini + `docs/architecture.md`, `docs/DECISIONS.md`)
- [ ] PHASE 2 — Database
- [ ] PHASE 3 — Laravel
- [ ] PHASE 4 — Authentication
- [ ] PHASE 5 — 2FA
- [ ] PHASE 6 — RBAC
- [ ] PHASE 7 — AdonisJS
- [ ] PHASE 8 — WebSocket
- [ ] PHASE 9 — Device registration
- [ ] PHASE 10 — Android SMB Lacak
- [ ] PHASE 11 — Heartbeat/reconnect
- [ ] PHASE 12 — Command broker
- [ ] PHASE 13 — Lock/unlock
- [ ] PHASE 14 — OTP
- [ ] PHASE 15 — Location
- [ ] PHASE 16 — Camera
- [ ] PHASE 17 — Vue Dashboard
- [ ] PHASE 18 — Telegram
- [ ] PHASE 19 — SMB Master
- [ ] PHASE 20 — Cloudflare
- [ ] PHASE 21 — Windows Server Launcher
- [ ] PHASE 22 — Testing
- [ ] PHASE 23 — Security hardening
- [ ] PHASE 24 — Production packaging

Setiap PHASE harus BUILD → TEST → VERIFY → DOCUMENT sebelum lanjut ke PHASE berikutnya (lihat §74/§81 spesifikasi asli). Tidak ada fase yang dinyatakan selesai tanpa itu.

## Development

Belum ada kode (PHASE 2 ke atas belum dimulai). Instruksi instalasi lokal akan ditambahkan mulai PHASE 3 (Laravel) dan PHASE 7 (AdonisJS).

## Lisensi & kepatuhan

Sistem ini ditujukan untuk perangkat yang dikelola secara sah (corporate-owned/BYOD dengan consent eksplisit pengguna dan kebijakan perusahaan tertulis). Lihat [docs/DECISIONS.md](docs/DECISIONS.md) untuk batasan keamanan yang tidak dapat diubah tanpa tinjauan ulang.
