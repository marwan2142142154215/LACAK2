# SMB — Database Schema (PostgreSQL)

PHASE 2 deliverable. Skema ini diimplementasikan sebagai Laravel migration di PHASE 3 (`smb-api/database/migrations/`). Semua tabel inti mengikuti §35: `id` (UUID, bukan auto-increment — alasan: device_id/command_id tidak boleh bisa ditebak/enumerasi, lihat §18/§21 IDOR protection), `created_at`, `updated_at`, FK ber-index, kolom pencarian ber-index, `soft_deletes` untuk data yang tidak boleh hilang permanen.

Konvensi: `uuid primary key default gen_random_uuid()` (ekstensi `pgcrypto`/`pg_uuid` wajib di-enable pada migration pertama).

---

## 1. `users`
Admin/operator yang login ke Web/Master (bukan device).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| name | varchar(100) | |
| email | varchar(150) unique, index | |
| password | varchar(255) | hashed (bcrypt/argon2id via Laravel) |
| two_factor_secret | text nullable | **encrypted** (Laravel `encrypted` cast), bukan plaintext |
| two_factor_recovery_codes | text nullable | encrypted, JSON array |
| two_factor_confirmed_at | timestamp nullable | |
| is_active | boolean default true | untuk suspend user tanpa hapus |
| last_login_at | timestamp nullable | |
| last_login_ip | varchar(45) nullable | |
| created_at, updated_at, deleted_at | timestamp | soft delete |

Role/permission: tabel `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` di-generate otomatis oleh **Spatie Laravel Permission** (PHASE 6) — tidak didesain manual.

## 2. `sites`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| name | varchar(150) index | |
| code | varchar(30) unique | kode singkat untuk display |
| address | text nullable | |
| is_active | boolean default true | |
| created_by | uuid FK -> users.id | |
| created_at, updated_at, deleted_at | | |

## 3. `teams`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| site_id | uuid FK -> sites.id, index | tim selalu dalam satu site |
| name | varchar(150) index | |
| code | varchar(30) | unique per site (`unique(site_id, code)`) |
| is_active | boolean default true | |
| created_by | uuid FK -> users.id | |
| created_at, updated_at, deleted_at | | |

## 4. `devices`
Entitas utama. `id` = **device_id** resmi (§18 — UUID server, immutable, bukan IMEI/MAC/phone number).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | = device_id, immutable |
| site_id | uuid FK -> sites.id, index | |
| team_id | uuid FK -> teams.id, index | |
| name | varchar(150) | label admin, boleh diubah |
| status | enum(ONLINE,DEGRADED,OFFLINE,UNKNOWN,LOCKED) index | dihitung server-side dari heartbeat (§6), bukan dari klaim client |
| is_managed | boolean default false | true jika Device Owner aktif (§7) |
| android_api_level | smallint nullable | |
| android_version | varchar(20) nullable | |
| app_version | varchar(20) nullable | |
| manufacturer | varchar(50) nullable | |
| model | varchar(100) nullable | |
| capability_report | jsonb nullable | snapshot terakhir §67 (camera_available, location_available, dst) |
| last_heartbeat_at | timestamp nullable index | dipakai untuk hitung status |
| last_seen_ip | varchar(45) nullable | |
| registered_at | timestamp nullable | |
| registered_by | uuid FK -> users.id nullable | admin yang generate registration code |
| is_active | boolean default true | admin bisa suspend device |
| created_at, updated_at, deleted_at | | |

Index tambahan: `(site_id, team_id, status)` composite untuk filter dashboard (§48).

## 5. `device_credentials`
Kredensial device terpisah dari tabel `devices` agar rotasi/expire tidak menyentuh data utama.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index, unique | one-to-one |
| credential_hash | varchar(255) | hash dari secret device (tidak pernah disimpan plaintext) |
| public_token_id | varchar(100) unique index | identifier publik dipakai device saat auth (bukan device_id langsung) |
| issued_at | timestamp | |
| rotated_at | timestamp nullable | |
| revoked_at | timestamp nullable index | |
| created_at, updated_at | | |

## 6. `device_sessions`
Sesi aktif (WebSocket/HTTPS) — divalidasi AdonisJS di setiap command push (§21).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index | |
| session_token_hash | varchar(255) | |
| connection_type | enum(WEBSOCKET,HTTPS_POLL) | |
| gateway_node | varchar(100) nullable | untuk multi-instance Adonis di masa depan |
| connected_at | timestamp | |
| last_activity_at | timestamp index | |
| disconnected_at | timestamp nullable | |
| disconnect_reason | varchar(100) nullable | |
| created_at, updated_at | | |

Session **tidak** disimpan di Redis sebagai source of truth (§42) — Redis hanya cache presence cepat; tabel ini adalah audit trail definitif siapa yang pernah connect.

## 7. `device_heartbeats`
Time-series — retensi dikonfigurasi (§71), partisi per bulan direkomendasikan saat volume besar (dicatat, implementasi partisi di PHASE 23 jika diperlukan).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index | |
| battery_level | smallint nullable | |
| network_type | varchar(20) nullable | WIFI/CELLULAR/NONE |
| signal_status | varchar(20) nullable | |
| connection_state | varchar(20) | |
| app_version | varchar(20) nullable | |
| android_version | varchar(20) nullable | |
| recorded_at | timestamp index | waktu di device |
| received_at | timestamp index | waktu server terima — dipakai hitung status, bukan `recorded_at` (anti clock-skew spoof) |
| created_at | | |

## 8. `device_locations`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index | |
| latitude | double precision | |
| longitude | double precision | |
| accuracy | real nullable | meter |
| source | varchar(20) | GPS/NETWORK/FUSED/LAST_KNOWN (§70 — jujur soal sumber) |
| recorded_at | timestamp index | |
| received_at | timestamp | |
| requested_by_command_id | uuid FK -> device_commands.id nullable | jika hasil dari command, bukan push rutin |
| created_at | | |

Composite index `(device_id, recorded_at desc)` untuk query history ter-paginasi (§26).

## 9. `device_registration_codes`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| code_hash | varchar(255) unique | kode sendiri di-hash, tidak disimpan plaintext (dicocokkan saat verifikasi) |
| site_id | uuid FK -> sites.id | |
| team_id | uuid FK -> teams.id | |
| created_by | uuid FK -> users.id | |
| expires_at | timestamp index | short-lived |
| used_at | timestamp nullable | single-use — null = belum dipakai |
| used_by_device_id | uuid FK -> devices.id nullable | |
| revoked_at | timestamp nullable | |
| created_at | | |

Constraint: `used_at IS NOT NULL` dicek via transaksi+lock (`SELECT ... FOR UPDATE`) saat validasi agar tidak ada race condition dua device klaim kode bersamaan (§17/§21).

## 10. `device_otps`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index | |
| otp_hash | varchar(255) | **hashed**, tidak plaintext (§24) |
| purpose | varchar(30) | UNLOCK |
| expires_at | timestamp index | short expiry |
| attempt_count | smallint default 0 | |
| max_attempts | smallint default 5 | |
| used_at | timestamp nullable | invalidated setelah sukses |
| requested_by | uuid FK -> users.id nullable | |
| created_at | | |

## 11. `device_commands`
Lihat §20–22. Ini tabel paling kritis untuk idempotency & anti-race.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | = command_id |
| device_id | uuid FK -> devices.id, index | |
| command_type | varchar(30) index | LOCK/UNLOCK/LOCATION_REQUEST/CAMERA_REQUEST/... |
| payload | jsonb nullable | parameter command |
| idempotency_key | varchar(100) | **unique composite** `(device_id, idempotency_key)` — mencegah duplicate (§22) |
| status | enum(PENDING,QUEUED,SENT,DELIVERED,RECEIVED,EXECUTING,SUCCESS,FAILED,EXPIRED,CANCELLED) index | |
| failure_reason | varchar(255) nullable | jujur, bukan generic (§69/§70) |
| created_by_type | varchar(20) | USER/TELEGRAM/SYSTEM |
| created_by_id | uuid nullable | polymorphic ref ke users/telegram_accounts |
| device_session_id | uuid FK -> device_sessions.id nullable | session yang dituju saat SENT — validasi ulang sebelum push |
| expires_at | timestamp index | command kedaluwarsa tidak dieksekusi meski terlambat sampai |
| sent_at, delivered_at, executed_at, completed_at | timestamp nullable | |
| created_at, updated_at | | |

**Constraint DB:** `UNIQUE (device_id, idempotency_key)` — level database, bukan hanya cek aplikasi, supaya aman dari race condition dua request bersamaan.

## 12. `device_command_logs`
Audit trail tiap perubahan status command (append-only, beda dari `device_commands` yang mutable current-state).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| command_id | uuid FK -> device_commands.id, index | |
| from_status | varchar(20) nullable | |
| to_status | varchar(20) | |
| note | text nullable | |
| actor | varchar(50) | DEVICE/GATEWAY/SYSTEM |
| created_at | index | |

## 13. `device_media`
Metadata foto — binary di disk storage yang dikonfigurasi lewat `MediaStorageService` (§110-114):
default lokal (`smb_media`, perangkat/server pemilik produk sendiri), opsional DigitalOcean
Spaces (`SMB_MEDIA_DISK=spaces`, §28) — tidak ada perubahan skema antara kedua mode.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index | |
| command_id | uuid FK -> device_commands.id nullable | |
| camera_facing | varchar(10) | FRONT/BACK |
| storage_path | varchar(500) | path relatif di disk storage (lokal atau Spaces), bukan URL publik |
| mime_type | varchar(50) | |
| size_bytes | bigint | |
| sha256_hash | varchar(64) | integrity check |
| captured_at | timestamp | |
| uploaded_at | timestamp nullable | |
| created_at, deleted_at | soft delete | |

Akses file: signed URL dengan expiration, di-generate on-demand oleh Laravel — tidak ada URL permanen disimpan di DB (§28).

## 14. `device_policies`
Policy yang berlaku untuk device/team/site (konfigurasi Device Owner, interval heartbeat, dll).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| scope_type | varchar(20) | DEVICE/TEAM/SITE/GLOBAL |
| scope_id | uuid nullable | null jika GLOBAL |
| policy_key | varchar(100) | |
| policy_value | jsonb | |
| created_by | uuid FK -> users.id | |
| created_at, updated_at | | |

Unique `(scope_type, scope_id, policy_key)`.

## 15. `telegram_accounts`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| telegram_id | bigint unique index | ID Telegram numerik, bukan username (§30) |
| telegram_username | varchar(50) nullable | hanya label |
| user_id | uuid FK -> users.id nullable | mapping ke user sistem untuk role/permission |
| status | enum(PENDING,APPROVED,REVOKED) index | default PENDING — tidak otomatis trusted |
| step_up_required | boolean default true | §30 untuk command sensitif |
| approved_by | uuid FK -> users.id nullable | |
| approved_at | timestamp nullable | |
| created_at, updated_at | | |

## 15b. `telegram_command_confirmations` (ditambahkan PHASE 18, tidak ada di desain awal §-numbered)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| telegram_account_id | uuid FK -> telegram_accounts.id, cascadeOnDelete | |
| device_id | uuid FK -> devices.id, cascadeOnDelete | |
| command_type | varchar(30) | LOCK/UNLOCK/LOCATION_REQUEST |
| payload | jsonb nullable | payload command yang akan dibuat setelah confirm (mis. pesan LOCK) |
| code_hash | varchar(255) | HASHED, tidak pernah plaintext |
| expires_at | timestamp | 5 menit sejak dibuat |
| attempt_count, max_attempts | smallint | default 0 / 3 |
| used_at | timestamp nullable | single-use |
| created_at | timestamp (useCurrent, tanpa updated_at) | |

Index `(telegram_account_id, expires_at)`. Lihat `docs/api.md` bagian Telegram Bot untuk catatan jujur soal step-up ini (confirm-before-execute, bukan MFA independen).

## 16. `audit_logs`
Disediakan otomatis oleh **Spatie Activity Log** (tabel `activity_log`) — dicatat untuk semua event §41. Tidak didesain ulang manual; dikonfigurasi di PHASE 3 untuk meng-log model `User`, `Device`, `DeviceCommand`, dsb, plus custom log call untuk event non-model (login, OTP, Telegram command).

## 17. `notifications`
Standar Laravel notifications table (dipakai untuk notifikasi internal dashboard) — skema default Laravel, tidak custom.

## 18. `system_settings`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| key | varchar(100) unique | |
| value | jsonb | |
| description | text nullable | |
| updated_by | uuid FK -> users.id nullable | |
| updated_at | | |

Dipakai untuk retention policy config (§71), app version info (§50), dsb.

## 19. `app_releases`
(Tambahan — diperlukan §50/§51, tidak disebut eksplisit nama tabelnya di §34 tapi datanya wajib ada.)

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| app_name | varchar(30) | SMB_LACAK/SMB_MASTER |
| version | varchar(20) | |
| version_code | integer | |
| min_supported_android_api | smallint | |
| release_notes | text nullable | |
| download_path | varchar(500) | |
| checksum_sha256 | varchar(64) | |
| released_at | timestamp | |
| is_active | boolean default true | |
| created_by | uuid FK -> users.id | |
| created_at | | |

Unique `(app_name, version_code)`.

---

## Retention policy (§71)

Dikonfigurasi lewat `system_settings` (key: `retention.heartbeat_days`, `retention.location_days`, `retention.command_log_days`, `retention.audit_days`, `retention.media_days`), dieksekusi oleh scheduled job Laravel (`php artisan schedule:run` — dibuat di PHASE 3) yang menghapus (soft delete lalu hard-delete setelah grace period) data melewati batas retensi. Default awal: heartbeat 30 hari, location 90 hari, command log 180 hari, audit 365 hari, media 90 hari — dapat diubah admin via dashboard tanpa deploy ulang.

## 14. `site_network_policies` (§98, ditambahkan PHASE 24)
Whitelist jaringan per Site — Site tanpa row di sini = default-open (§131).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| site_id | uuid FK -> sites.id, index | |
| network_type | enum(IP,CIDR) | |
| value | varchar(100) | IP tunggal atau notasi CIDR |
| description | varchar(255) nullable | |
| is_active | boolean default true | |
| created_by/updated_by | uuid FK -> users.id nullable | |
| deleted_at | soft delete | |

## 15. `device_network_violations` (§103-106, ditambahkan PHASE 24)
Satu row per "episode" pelanggaran (NORMAL->VIOLATION->RESOLVED) — BUKAN satu row per heartbeat (§105 anti alert-spam).

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | uuid PK | |
| device_id | uuid FK -> devices.id, index bareng resolved_at | |
| site_id | uuid FK -> sites.id | |
| observed_ip | varchar(45) nullable | |
| policy_status | enum(BLOCKED,UNKNOWN) | |
| severity | enum(INFO,WARNING,HIGH,CRITICAL) | |
| first_seen_at / last_seen_at | timestamp | |
| alert_sent_at | timestamp nullable | |
| resolved_at | timestamp nullable | NULL = episode masih terbuka |

## Index summary (anti N+1 / dashboard filter §48)

- `devices(site_id, team_id, status)`
- `devices(last_heartbeat_at)`
- `device_commands(device_id, status, created_at)`
- `device_commands(device_id, idempotency_key)` UNIQUE
- `device_locations(device_id, recorded_at)`
- `device_heartbeats(device_id, received_at)`
- `device_registration_codes(code_hash)` UNIQUE, `(expires_at)`
- `telegram_accounts(telegram_id)` UNIQUE
- `site_network_policies(site_id, is_active)`
- `device_network_violations(device_id, resolved_at)`, `(site_id, resolved_at)`

## Catatan migrasi manual (§35)

Tidak ada perubahan skema production manual — semua lewat `php artisan migrate` dengan file migration bernomor urut, direview sebelum apply (lihat `backend-design:migration-safety` checklist dipakai di PHASE 3 saat menulis file migration riil).
