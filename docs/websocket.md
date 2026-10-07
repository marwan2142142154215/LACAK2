# SMB — WebSocket Gateway (AdonisJS)

PHASE 8 deliverable. Endpoint: `wss://ws.lacaksmbbot.com` (production) / `ws://127.0.0.1:3334` (lokal). Path Socket.IO: `/ws`. Transport: **websocket murni** — HTTP long-polling Engine.IO dimatikan (`transports: ['websocket']`), karena:

1. §43/§44 secara eksplisit minta WebSocket, bukan polling.
2. Polling internal Engine.IO (request HTTP biasa ke path yang sama) bentrok dengan router AdonisJS yang listen di `http.Server` yang sama persis — ditemukan sebagai bug nyata saat testing PHASE 8 (lihat `docs/DECISIONS.md` riwayat commit).

Fallback "HTTPS" yang dimaksud §45 adalah endpoint REST **kita sendiri** (command sync polling, dibangun PHASE 11/12) — bukan transport fallback bawaan Socket.IO.

## 1. Autentikasi handshake (§43)

Device **wajib** mengirim ketiganya saat connect — bukan `device_id` saja:

```js
io('wss://ws.lacaksmbbot.com', {
  path: '/ws',
  transports: ['websocket'],
  auth: {
    device_id: '...',          // UUID dari hasil registration (PHASE 9)
    public_token_id: '...',    // identifier publik kredensial (bukan device_id langsung)
    device_secret: '...',      // secret mentah, HANYA dikirim saat handshake — tidak pernah disimpan server
  },
})
```

Verifikasi berjalan di **`io.use()` middleware** (bukan di dalam handler `connection`) — ini penting secara arsitektural: begitu handler `connection` terpanggil, client Socket.IO **sudah** menerima event `connect` di level transport. Menolak koneksi sesudah titik itu (lewat `socket.disconnect()`) datang terlambat — client akan sempat melihat `connect` lalu `disconnect`, bukan `connect_error` yang semestinya. Middleware `next(new Error(...))` menolak handshake **sebelum** `connect` terkirim ke client, menghasilkan event `connect_error` yang benar. (Ditemukan sebagai bug nyata lewat test end-to-end, bukan asumsi — lihat `app/services/websocket_service.ts`.)

Alur verifikasi:
1. `device_id` + `public_token_id` dicocokkan ke `device_credentials` (harus ada, `revoked_at IS NULL`).
2. `device_secret` dicocokkan ke `credential_hash` via **bcrypt** (hash diterbitkan Laravel saat registration — bcrypt dipilih spesifik karena formatnya portable PHP↔Node, dua sisi bisa verifikasi hash yang sama tanpa duplikasi algoritma).
3. Device dicek `is_active = true`.
4. Device join room Socket.IO `device:{device_id}` — dipakai command broker (PHASE 12) untuk target push yang tepat (§21 anti wrong-device).

## 2. Event

| Event | Arah | Payload | Keterangan |
|---|---|---|---|
| `connect_error` | server→client | `Error.message` | Handshake ditolak (kredensial salah/revoked/device nonaktif/field kurang) |
| `device.connected` | server→client | `{device_id, connected_at}` | Autentikasi sukses, device_sessions row dibuat, presence di-set |
| `device.disconnected` | server→room `device:{id}` | `{device_id, reason}` | Socket putus (alasan dari Socket.IO: `client namespace disconnect`, `transport close`, dst) |
| `connection.error` | server→client | `{message}` | Gagal SETELAH handshake diterima (misal DB error saat create session) — device tetap diputus, tapi diberi sinyal jelas (§70) |
| `device.heartbeat` | client→server | `{request_id, recorded_at, battery_level, network_type, connection_state, app_version, android_version}` | §6 — jalur UTAMA heartbeat (bukan HTTPS). Dikirim device tiap 30s selama socket terhubung |
| `device.heartbeat.ack` | server→client | `{accepted, status, server_received_at}` | Balasan heartbeat — `status` dihitung server dari `DeviceStatusResolver` (identik dengan versi PHP di smb-api, lihat `app/services/device_status_resolver.ts`) |

| `device.command.created` | server→room `device:{id}` | `{command_id, command_type, payload, expires_at}` | §19 — command dikirim setelah Laravel membuat row & memberi tahu AdonisJS via internal API |
| `device.command.ack` | client→server | `{command_id, status, failure_reason?}` | §20 — device melaporkan progres (`DELIVERED`→`RECEIVED`→`EXECUTING`→`SUCCESS`/`FAILED`). Tidak ada balasan langsung; status baru terlihat lewat command history API |

### Command broker (PHASE 12, §19-22)

Alur: `Laravel (create PENDING) → POST /internal/commands/dispatch → AdonisJS (cek device online + belum expired) → emit device.command.created → device ack → AdonisJS validasi & update status`.

Dua proteksi yang **ditegakkan di `#handleCommandAck`**, bukan sekadar didokumentasikan:
1. **Anti wrong-device (§21):** `command.device_id` harus sama dengan `socket.data.deviceId` (diambil dari hasil autentikasi `io.use()`, bukan dari payload ack yang bisa dipalsukan). Device A **tidak bisa** meng-ack command milik device B walau tahu `command_id`-nya — diverifikasi via test end-to-end.
2. **Forward-only state machine (§20/§21):** `isValidDeviceAckTransition()` (`app/services/command_status_transition.ts`) menolak transisi mundur atau menimpa status terminal (`SUCCESS`/`FAILED`/`EXPIRED`/`CANCELLED`). Command yang sudah `SUCCESS` tidak bisa "dimundurkan" ke `EXECUTING` oleh ack yang terlambat/nyasar.

Command yang sudah `expires_at`-nya lewat **sebelum** sempat dikirim ditandai `EXPIRED` saat `dispatchCommand()` dipanggil, bukan tetap dikirim (§21 "command expired tetap dieksekusi" — dicegah). Command yang device-nya sedang tidak terhubung dibiarkan `PENDING` (belum ada mekanisme command-sync-on-reconnect — dicatat sebagai lanjutan, lihat kode `dispatchCommand`).

## 3. Side effect per koneksi

- **Connect:** insert `device_sessions` (connection_type=WEBSOCKET, connected_at, session_token_hash — token internal gateway, tidak dikirim ke client), `SET device:presence:{device_id} EX 120` di Redis.
- **Disconnect:** update `device_sessions.disconnected_at` + `disconnect_reason`, `DEL device:presence:{device_id}`.

Redis presence **bukan** source of truth (§42) — kalau Redis down/flush, status ONLINE/OFFLINE tetap bisa dihitung dari `devices.last_heartbeat_at` (PHASE 11). Presence murni cache cepat untuk query "siapa yang online sekarang" tanpa scan tabel heartbeat.

## 4. Internal API (service-to-service, §3)

Prefix `/api/v1/internal/*`, wajib header `x-internal-secret` (dibandingkan **timing-safe**, lihat `app/middleware/internal_auth_middleware.ts`). Endpoint saat ini:

| Endpoint | Keterangan |
|---|---|
| `GET /api/v1/internal/ping` | Health check service-to-service dari Laravel |

Endpoint `command.created` (Laravel → AdonisJS, memicu push command ke device via room) ditambahkan PHASE 12.

## 5. Testing

6 test fungsional (`tests/functional/websocket.spec.ts`) memakai `socket.io-client` nyata terhadap server nyata (bukan mock): auth sukses, secret salah, `public_token_id` tidak dikenal, field hilang, credential revoked, dan verifikasi `device_sessions`/presence Redis ter-update benar saat connect & disconnect.
