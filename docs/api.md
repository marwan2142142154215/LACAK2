# SMB — API Reference (Laravel, `smb-api`)

Base URL lokal: `http://127.0.0.1:8000/api/v1`. Production: `https://api.lacaksmbbot.com/api/v1`.

Format response standar (§36):
```json
// sukses
{ "success": true, "message": "...", "data": {} }
// gagal
{ "success": false, "message": "...", "errors": {} }
// list (paginated)
{ "success": true, "message": "...", "data": [...], "meta": {"total":0,"current_page":1,"last_page":1,"per_page":15} }
```

Dokumen ini diperluas tiap PHASE menambahkan endpoint baru — bukan ditulis sekali di akhir.

## Auth (PHASE 4/5)

| Method | Path | Auth | Keterangan |
|---|---|---|---|
| POST | `/auth/login` | publik, throttle `login` | `{email, password, device_name}`. Jika 2FA aktif → `{two_factor_required:true, login_token}` (bukan token). |
| POST | `/auth/two-factor-challenge` | publik, throttle `two-factor-login` | `{login_token, code\|recovery_code, device_name}` → token |
| POST | `/auth/logout` | Sanctum | Revoke token yang sedang dipakai |
| GET | `/auth/me` | Sanctum | Profil + roles + permissions |
| GET | `/auth/sessions` | Sanctum | Daftar token/device aktif milik user ini |
| DELETE | `/auth/sessions/{tokenId}` | Sanctum | Revoke session lain (scoped ke user sendiri, anti-IDOR) |
| GET/POST | `/auth/two-factor` | Sanctum | Lihat status 2FA / mulai setup (QR + manual key) |
| POST | `/auth/two-factor/confirm` | Sanctum | `{code}` → aktifkan 2FA, kembalikan recovery codes (sekali) |
| GET/POST | `/auth/two-factor/recovery-codes` | Sanctum | Lihat / regenerasi (butuh `current_password`) |
| DELETE | `/auth/two-factor` | Sanctum | Matikan 2FA (butuh `current_password`, step-up §30) |

## Sites & Teams (PHASE 6 — permission `sites.manage` / `teams.manage`)

| Method | Path | Keterangan |
|---|---|---|
| GET | `/sites` | Paginated, `?search=`, `?per_page=` |
| POST | `/sites` | `{name, code, address?, is_active?}` |
| GET/PATCH/DELETE | `/sites/{site}` | DELETE = soft delete |
| GET | `/teams?site_id=` | Paginated |
| POST | `/teams` | `{site_id, name, code, is_active?}` — `code` unik per `site_id` |
| GET/PATCH/DELETE | `/teams/{team}` | |

## Device Registration (PHASE 9)

| Method | Path | Auth | Keterangan |
|---|---|---|---|
| POST | `/devices/registration-codes` | Sanctum, permission `devices.create` | `{site_id, team_id, expires_in_minutes?}` → `{code, expires_at}`. **Kode plaintext hanya tampil di response ini, sekali.** |
| GET | `/devices/registration-codes` | Sanctum, permission `devices.create` | Riwayat (tanpa `code_hash`), paginated |
| POST | `/devices/register` | **publik**, throttle `device-registration` | `{code, android_api_level?, android_version?, app_version?, manufacturer?, model?, capability_report?}` → `{device_id, public_token_id, device_secret}`. **`device_secret` hanya tampil sekali** — dipakai device untuk autentikasi WebSocket (lihat `docs/websocket.md`). Kode single-use & short-lived, divalidasi dengan row lock (`SELECT ... FOR UPDATE`) untuk anti race-condition. |

Error khusus device registration: `404` kode tidak ditemukan, `422` kode kedaluwarsa/dicabut/sudah dipakai.

## Device Heartbeat — HTTPS fallback (PHASE 11, §45)

| Method | Path | Auth | Keterangan |
|---|---|---|---|
| POST | `/devices/heartbeat` | device credential (`device_id`+`public_token_id`+`device_secret` di body, bukan Sanctum) | Jalur **fallback** — jalur utama heartbeat lewat WebSocket (`device.heartbeat` event, lihat `docs/websocket.md`). `{battery_level?, network_type?, connection_state?, app_version?, android_version?}` → `{accepted, status, server_received_at}` |

Status (`ONLINE`/`DEGRADED`/`OFFLINE`/`UNKNOWN`) dihitung server-side dari `last_heartbeat_at` (§6) — lihat `App\Services\DeviceStatusResolver`. Command terjadwal `smb:recompute-device-statuses` (tiap menit) menurunkan status device yang berhenti heartbeat tanpa menunggu heartbeat baru masuk.

## Device Commands — command broker (PHASE 12, permission `devices.command`/`devices.view`)

| Method | Path | Keterangan |
|---|---|---|
| POST | `/devices/{device}/commands` | `{command_type, payload?, idempotency_key?, expires_in_seconds?}` → command `PENDING`, AdonisJS diberi tahu otomatis. `command_type` ∈ `LOCK,UNLOCK,LOCATION_REQUEST,CAMERA_REQUEST`. Mengirim `idempotency_key` yang sama untuk device yang sama mengembalikan command LAMA (200), bukan membuat baru (§22). |
| GET | `/devices/{device}/commands` | Riwayat command, paginated (§48) |
| GET | `/devices/{device}/commands/{command}` | Detail + log transisi status. 404 kalau command bukan milik `{device}` (anti-IDOR) |

Status command: `PENDING→QUEUED→SENT→DELIVERED→RECEIVED→EXECUTING→SUCCESS|FAILED`, atau `EXPIRED`/`CANCELLED` dari status non-terminal manapun. Command terjadwal `smb:expire-stale-commands` (tiap menit) menandai `EXPIRED` command yang lewat `expires_at` tapi belum terminal (§21). Detail lengkap mekanisme anti wrong-device & state machine di `docs/websocket.md`.

## Health (PHASE 3/4/7)

| Method | Path | Keterangan |
|---|---|---|
| GET | `/health` | Cek Laravel + PostgreSQL + Redis + Storage nyata (bukan hardcoded OK) |

## Rute yang BELUM ada (menyusul per PHASE)

- Device CRUD/detail/lock/unlock/location/camera/command — PHASE 12-16
- User management — ditambahkan saat dibutuhkan (lihat README checklist)
- Telegram webhook — PHASE 18
- Reporting/export — menyusul
