# SMB — Device Management & Tracking Platform — Architecture

> Lihat [DECISIONS.md](DECISIONS.md) untuk keputusan keamanan baseline (DEC-001) sebelum membaca dokumen ini.

## 1. Ringkasan

SMB adalah platform device-management untuk mengelola perangkat Android **milik/dikelola secara sah** oleh organisasi (corporate-owned atau BYOD dengan consent & kebijakan perusahaan). Terdiri dari dua service backend dengan tanggung jawab terpisah, dua aplikasi Android, satu web dashboard, satu bot Telegram, dan infrastruktur Cloudflare untuk exposure publik yang aman dari server Windows lokal.

## 2. Komponen & tanggung jawab

| Komponen | Teknologi | Tanggung jawab |
|---|---|---|
| `smb-api` | Laravel (PHP), PostgreSQL, Sanctum, Spatie Permission, Spatie Activity Log | Business logic, auth, RBAC, site/team/device management, registration code, command **records**, audit, OTP, device policy, location records, media metadata, reporting, DB transactions |
| `smb-gateway` | Node.js LTS + AdonisJS | Device gateway realtime: WebSocket, presence, heartbeat, reconnect handling, command **delivery & ack**, device session, realtime event ke dashboard & Telegram |
| `smb-web` | Vue 3 + Vite + Tailwind + Pinia + Axios + VeeValidate + TanStack Table + ApexCharts | Dashboard admin (Bahasa Indonesia) |
| `smb-master-android` | Kotlin, API 26–36 | App admin/operator mobile (SMB Master) — tidak pernah terhubung langsung ke SMB Lacak |
| `smb-tracker-android` | Kotlin, API 26–36 | Managed-device agent (SMB Lacak) |
| `smb-server-launcher` | Windows service/launcher | Start/monitor Laravel, AdonisJS, web, PostgreSQL, Redis, Cloudflare Tunnel; SMB Doctor health check |
| `cloudflare/` | Cloudflare Tunnel (`cloudflared`) | Expose server Windows lokal ke domain publik tanpa inbound port |

## 3. Mengapa dua backend (Laravel + AdonisJS)

Ini **bukan** duplikasi — pemisahan berdasarkan sifat beban kerja:

- **Laravel** menangani *state transaksional* (RBAC, audit, record command, OTP hashing, billing/reporting) — konsisten dengan ekosistem Spatie yang matang untuk permission & activity log, dan transaction-safety PostgreSQL via Eloquent.
- **AdonisJS** menangani *realtime & koneksi persisten* (ribuan WebSocket device yang reconnect, heartbeat, presence) — Node event-loop lebih cocok untuk I/O-bound persistent connections dibanding PHP request-response model Laravel.

Device **tidak pernah** terhubung langsung ke Laravel untuk command delivery; Laravel hanya membuat *command record* (PENDING), AdonisJS yang mengirim lewat WebSocket dan melaporkan status ack kembali ke Laravel (lihat §19–22 command architecture, dan [websocket.md](websocket.md) setelah PHASE 8).

## 4. Diagram komunikasi

```
                          CLOUDFLARE EDGE
                    (WAF, DDoS, TLS, DNS proxy)
                                |
                     lacaksmbbot.com (+ subdomains)
                                |
                 +--------------+---------------+
                 |                               |
              HTTPS                             WSS
                 |                               |
        api.lacaksmbbot.com            ws.lacaksmbbot.com
                 |                               |
         +-------v-------+               +-------v-------+
         |  smb-api      |               | smb-gateway   |
         |  (Laravel)    |<--- internal ->| (AdonisJS)   |
         +-------+-------+   HTTP/Redis   +-------+-------+
                 |                               |
                 +---------------+---------------+
                                 |
                          +------v------+
                          | PostgreSQL  |  <- source of truth
                          +------+------+
                                 |
                          +------v------+
                          |   Redis     |  <- cache/presence only
                          +-------------+

Cloudflare Tunnel (cloudflared) berjalan sebagai Windows service di
smb-server-launcher, menghubungkan origin lokal (localhost:PORT) ke
edge Cloudflare tanpa membuka port inbound di router.

         SMB Lacak (Android, managed device)
              | HTTPS (api.lacaksmbbot.com)  -> registration, heartbeat fallback, media upload meta
              | WSS   (ws.lacaksmbbot.com)   -> realtime command, presence, location push
              v
         Cloudflare -> smb-gateway (Adonis) <-> smb-api (Laravel)

         SMB Master (Android, admin/operator)
              | HTTPS + WSS (read dashboard data, issue commands)
              v
         Cloudflare -> smb-api (command creation) -> smb-gateway (delivery) -> SMB Lacak
         (SMB Master TIDAK PERNAH connect langsung ke SMB Lacak)

         Telegram Bot
              | webhook
              v
         smb-gateway/smb-api -> authorization check -> command -> device
```

## 5. Command flow (ringkas — detail penuh di PHASE 12)

```
Caller (Web/Master/Telegram)
   -> Laravel: AuthN (Sanctum) -> AuthZ (Spatie permission) -> ownership check (site/team)
   -> Laravel: create device_commands row (status=PENDING, idempotency_key, expires_at)
   -> Laravel -> Adonis (internal API/event): command.created
   -> Adonis: validate device session masih valid & device_id cocok -> push via WS (status=SENT)
   -> Device: ack DELIVERED -> RECEIVED -> EXECUTING -> SUCCESS/FAILED
   -> Adonis -> Laravel: update status + command log (audit)
   -> Adonis -> Web/Telegram: realtime event
```

Validasi anti-race-condition (§21–22) terjadi di **setiap hop**: command_id + device_id + device session token + expiry dicek ulang oleh Adonis sesaat sebelum push ke socket device yang benar-benar aktif, bukan hanya saat command dibuat di Laravel.

## 6. Data store

- **PostgreSQL** — source of truth untuk seluruh entitas (lihat skema di PHASE 2 / [database.md] akan dibuat).
- **Redis** — cache, device presence (ephemeral key `device:presence:{id}` dengan TTL = heartbeat timeout), job non-transaksional. Tidak pernah jadi satu-satunya tempat state command/audit.
- **DigitalOcean Spaces** — binary media (foto), DB hanya simpan metadata + signed URL generation.

## 7. Environment & domain

```
LOCAL        -> http://localhost:*  (dev only)
STAGING      -> *.staging.lacaksmbbot.com (di PHASE 20+)
PRODUCTION   -> https://app.lacaksmbbot.com   (smb-web)
                https://api.lacaksmbbot.com   (smb-api)
                wss://ws.lacaksmbbot.com      (smb-gateway)
                https://download.lacaksmbbot.com (APK/package distribution)
```

## 8. Status implementasi

Dokumen ini adalah output PHASE 1. Lihat [README.md](../README.md) untuk urutan PHASE 2–24 dan status masing-masing.
