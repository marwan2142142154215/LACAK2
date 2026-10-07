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

## Yang HARUS Anda lakukan sendiri (butuh akun Cloudflare Anda — tidak bisa diotomasi dari sesi ini)

Sesi ini **tidak punya akses** ke akun Cloudflare Anda (tidak ada API token, tidak ada
akses dashboard) — langkah berikut WAJIB dijalankan manual oleh Anda:

1. **Tambahkan domain** `lacaksmbbot.com` ke Cloudflare (ganti nameserver di registrar domain Anda sesuai instruksi Cloudflare).
2. **Install `cloudflared`** di perangkat server Anda:
   - Windows: unduh `cloudflared-windows-amd64.exe` dari halaman release resmi Cloudflare, atau `winget install --id Cloudflare.cloudflared`.
3. **Login & buat tunnel**:
   ```bash
   cloudflared tunnel login
   cloudflared tunnel create smb-platform
   ```
   Ini menghasilkan file credential JSON (`~/.cloudflared/<tunnel-id>.json`) — **JANGAN commit file ini ke Git**.
4. **Route DNS** untuk setiap subdomain ke tunnel:
   ```bash
   cloudflared tunnel route dns smb-platform app.lacaksmbbot.com
   cloudflared tunnel route dns smb-platform api.lacaksmbbot.com
   cloudflared tunnel route dns smb-platform ws.lacaksmbbot.com
   cloudflared tunnel route dns smb-platform download.lacaksmbbot.com
   ```
5. **Edit `config.yml`** di folder ini: isi `tunnel:` dengan tunnel ID Anda, `credentials-file:` dengan path file JSON dari langkah 3.
6. **Jalankan sebagai Windows service** (supaya tunnel tetap hidup setelah reboot, §35):
   ```bash
   cloudflared service install --config "C:\SMB\cloudflare\config.yml"
   ```
   Ini mendaftarkan `cloudflared` sebagai Windows Service resmi (`sc query cloudflared` untuk cek statusnya) — bukan proses yang hilang saat terminal ditutup.
7. **WAF & rate limiting**: dikonfigurasi di dashboard Cloudflare (Security → WAF), bukan lewat file konfigurasi — lihat bagian "Rekomendasi WAF" di bawah, tapi aktivasinya manual lewat web UI akun Anda.

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
