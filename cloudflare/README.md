# Cloudflare — SMB (PHASE 20)

Domain produksi: `lacaksmbbot.com`. Cloudflare dipakai untuk DNS, HTTPS, WAF/rate-limiting,
dan **Cloudflare Tunnel** (§35) — supaya server (perangkat Anda sendiri, lihat
`docs/DECISIONS.md` DEC-007) tidak perlu membuka port inbound di router/firewall sama
sekali.

```
Internet
  │
  ▼
Cloudflare (DNS + HTTPS + WAF)
  │
  ▼
Cloudflare Tunnel (cloudflared)
  │
  ▼
Perangkat Anda (localhost: smb-web / smb-api / smb-gateway)
```

## Yang sudah disiapkan di repo ini

- `config.yml` — template konfigurasi `cloudflared` (ingress rules per subdomain → service lokal).
- `env.example` — variabel yang dibutuhkan (`CLOUDFLARE_TUNNEL_TOKEN`, dst), jangan commit nilai asli.

## Status akses (diperbarui)

Pemilik produk sudah memberi API Token + Account ID Cloudflare ke sesi ini. **Tunnel ID
sudah diisi** di `config.yml` (`016877bc-4115-4700-9e16-0ad256e82d64`) — berarti tunnel-nya
sudah dibuat sebelumnya (via dashboard atau CLI).

Yang TIDAK bisa diselesaikan dari sesi ini: `api.cloudflare.com` diblokir oleh kebijakan
jaringan environment cloud sesi ini sendiri (bukan masalah token) — tidak bisa memverifikasi
zona/DNS/tunnel lewat API dari sini sampai itu dibuka (menu environment → Edit → Network
access → tambahkan `api.cloudflare.com`). **Dan yang TIDAK AKAN PERNAH bisa dari sesi
manapun**: menjalankan `cloudflared` itu sendiri — itu harus jalan sebagai proses/service
DI PC ANDA, sesi ini tidak punya akses remote ke komputer fisik Anda.

## Langkah yang Anda jalankan SENDIRI di PC Anda (tidak butuh saya/API, cukup `cloudflared` CLI Anda yang sudah login)

1. **Tambahkan domain** `lacaksmbbot.com` ke Cloudflare (ganti nameserver di registrar domain Anda sesuai instruksi Cloudflare) — kalau belum.
2. **Install `cloudflared`** di PC Anda (server):
   - Windows: `winget install --id Cloudflare.cloudflared`, atau unduh `cloudflared-windows-amd64.exe` dari halaman release resmi Cloudflare.
3. **Dapatkan credential tunnel yang SUDAH ADA** (`016877bc-4115-4700-9e16-0ad256e82d64`):
   - Kalau tunnel dibuat via `cloudflared tunnel login` + `cloudflared tunnel create` sebelumnya: file JSON-nya ada di `~/.cloudflared/016877bc-4115-4700-9e16-0ad256e82d64.json` di PC yang dipakai saat membuatnya.
   - Kalau dibuat via dashboard Zero Trust (Networks → Tunnels → Install connector): ambil **connector token** dari situ (bukan API token yang sudah diberikan — ini token khusus per-tunnel), lalu jalankan langsung tanpa perlu `config.yml`:
     ```bash
     cloudflared service install <CONNECTOR_TOKEN_DARI_DASHBOARD>
     ```
     (lewati langkah 4-6 di bawah kalau pakai cara ini — ingress rule-nya diatur di dashboard Zero Trust, bukan `config.yml` lokal.)
4. **Route DNS** untuk setiap subdomain ke tunnel (hanya kalau pakai `config.yml` lokal, bukan dashboard):
   ```bash
   cloudflared tunnel route dns 016877bc-4115-4700-9e16-0ad256e82d64 app.lacaksmbbot.com
   cloudflared tunnel route dns 016877bc-4115-4700-9e16-0ad256e82d64 api.lacaksmbbot.com
   cloudflared tunnel route dns 016877bc-4115-4700-9e16-0ad256e82d64 ws.lacaksmbbot.com
   cloudflared tunnel route dns 016877bc-4115-4700-9e16-0ad256e82d64 download.lacaksmbbot.com
   ```
5. **Edit `credentials-file:`** di `cloudflare/config.yml` (sudah ada di repo Anda) — isi path file JSON dari langkah 3.
6. **Jalankan sebagai Windows service** (supaya tunnel tetap hidup setelah reboot, §35):
   ```bash
   cloudflared service install --config "C:\SMB\cloudflare\config.yml"
   ```
   Ini mendaftarkan `cloudflared` sebagai Windows Service resmi (`sc query cloudflared` untuk cek statusnya) — bukan proses yang hilang saat terminal ditutup.
7. **WAF & rate limiting**: dikonfigurasi di dashboard Cloudflare (Security → WAF) — lihat "Rekomendasi WAF" di bawah. Kalau network access API dibuka untuk sesi ini, saya bisa bantu cek/atur sebagian lewat API juga.

## Status integrasi dengan `smb-server-launcher`

`smb-server-launcher` (PHASE 21) sekarang memeriksa status **struktural** cloudflared
(binary ada di PATH + `config.yml` ada + — kalau cloudflared mendukungnya tanpa login —
`cloudflared tunnel info`), BUKAN konektivitas tunnel yang sesungguhnya (itu butuh akun
Cloudflare asli Anda yang tidak tersedia di sesi pengembangan ini). Setelah Anda
menyelesaikan langkah 1-6 di atas, jalankan `smb-server.exe doctor` lagi — baris
"Cloudflare Tunnel" akan berubah dari `UNKNOWN`/`NOT_CONFIGURED` ke `OK` begitu service
Windows `cloudflared` benar-benar berjalan.

## Rekomendasi WAF & security headers (§35, diaktifkan manual di dashboard)

- **WAF**: aktifkan "Cloudflare Managed Ruleset" (Security → WAF → Managed rules).
- **Rate limiting**: tambahkan rule rate-limit untuk `/api/v1/auth/login` dan
  `/api/v1/auth/two-factor-challenge` di level Cloudflare SEBAGAI TAMBAHAN (bukan pengganti)
  rate-limit Laravel yang sudah ada (`throttle:login`, `throttle:two-factor-login`) —
  defense-in-depth, bukan duplikasi sia-sia (Cloudflare menahan sebelum request sampai ke
  server Anda sama sekali).
- **SSL/TLS mode**: "Full (strict)" — bukan "Flexible" (§10/§44, WAJIB HTTPS end-to-end).
- **Always Use HTTPS**: ON.
- **Minimum TLS Version**: 1.2.
- **Browser Integrity Check**: ON (membantu menyaring bot sederhana sebelum sampai ke API).

Cloudflare Tunnel sendiri SUDAH end-to-end encrypted tanpa perlu buka port, jadi origin
server Anda TIDAK butuh sertifikat TLS sendiri — tapi tetap pastikan traffic
internal/localhost antar service (Laravel↔AdonisJS↔PostgreSQL) berjalan di jaringan lokal
tepercaya (loopback), bukan network terbuka.
