# Load test — SMB Gateway (PHASE 24, §41/§42)

Simulasi N device terhubung bersamaan ke `smb-gateway` (WebSocket/Socket.IO asli, memakai
library client yang SAMA — `socket.io-client` — yang dipakai test suite gateway sendiri),
mengukur latency connect + heartbeat. **Ini benar-benar dijalankan di sesi ini** terhadap
`smb-api` + `smb-gateway` yang sungguhan berjalan lokal — bukan skrip yang hanya ditulis
tanpa dicoba (§66/§79).

## Prasyarat

```bash
cd scripts/load-test
npm install   # cuma socket.io-client
```

## 1. Seed device untuk testing

Device di load test harus device ASLI yang ada di database (biar auth-nya nyata, bukan
dipalsukan) — jangan jalankan ke database produksi.

```bash
php scripts/load-test/seed-devices.php 100   # dari root repo
# -> scripts/load-test/devices.json (device_id + public_token_id + device_secret, PLAINTEXT
#    secret — hanya untuk testing, TIDAK PERNAH masuk Git, lihat .gitignore)
```

## 2. Load test agregat

```bash
node device-heartbeat-load.mjs ws://127.0.0.1:3334 ./devices.json 15
# argumen: <gateway ws url> <path devices.json> <ramp delay ms per device>
```

## 3. Cek responsivitas gateway SELAMA badai koneksi (dua proses terpisah, §42)

```bash
node baseline-pinger.mjs ws://127.0.0.1:3334 ./devices.json &
node burst-generator.mjs ws://127.0.0.1:3334 ./devices.json
```

`baseline-pinger.mjs` menyambung SATU device lebih dulu dan heartbeat tiap 500ms selama
40 detik; `burst-generator.mjs` menyambungkan SISA device lain secara bersamaan. Kalau
device baseline (yang sudah online, tidak ikut proses auth baru) ikut telat dibalas
selama badai berlangsung, itu tanda gateway tidak cukup responsif — persis skenario
reconnect storm §42 (server restart → banyak device reconnect sekaligus).

## Hasil NYATA yang sudah diukur di sesi ini (100 device, container 4 core, 99/99 auth SUKSES diverifikasi eksplisit)

| Kondisi | Waktu total 99 koneksi baru | Heartbeat device lain SELAMA badai |
|---|---|---|
| **`bcryptjs`** (pure-JS, versi awal `websocket_service.ts`) | **35,4 detik** | delay sampai **34,8 detik** — gateway nyaris berhenti total |
| **`bcrypt`** native + fix `$2y$` (lihat `docs/DECISIONS.md` DEC-009) | **7,9 detik** | **4-19 ms** — praktis tidak terganggu |

**~4,5x lebih cepat** untuk waktu total, dan yang lebih berharga: device lain yang sudah
online **tidak lagi ikut macet** selama badai auth (itu yang paling penting untuk
skenario reconnect-storm §42). Diukur BERULANG (3x) di lingkungan bersih dengan hasil
konsisten. **Catatan jujur**: percobaan pertama sempat salah mengukur ~130x karena bug
kompatibilitas hash `$2y$` yang membuat semua auth GAGAL CEPAT (bukan berhasil cepat) —
ditemukan & diperbaiki sebelum angka itu di-commit sebagai fakta. Kronologi lengkap di
`docs/DECISIONS.md` DEC-009.

## Keterbatasan yang jujur dicatat

- **Skala yang diuji: 100 device**, bukan 1.000-10.000 (§41 meminta rentang itu). Container
  dev sesi ini hanya punya 4 core CPU — menjalankan 10.000 koneksi dari proses Node yang
  SAMA (client generator) akan membuat CLIENT itu sendiri jadi bottleneck (bukan server
  yang diuji) tanpa banyak mesin/proses klien terdistribusi. **Tindak lanjut pemilik
  produk**: jalankan `device-heartbeat-load.mjs` dari beberapa mesin/container klien
  berbeda secara bersamaan terhadap server staging/produksi untuk skala 1.000+.
- Database di sesi ini adalah `smb_testing` lokal (bukan server produksi via Cloudflare
  Tunnel) — latency jaringan nyata (bukan loopback `127.0.0.1`) belum terukur.
- Reconnect storm SETELAH server restart (bukan hanya banyak device BARU connect) belum
  diuji secara spesifik — skenario yang diuji di sini (banyak device baru connect
  bersamaan) adalah proxy yang representatif untuk itu, tapi bukan pengujian restart
  sungguhan.
