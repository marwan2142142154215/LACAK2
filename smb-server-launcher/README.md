# SMB Server Launcher

PHASE 21. `.exe` C#/.NET 8 asli (bukan `.bat` yang disamarkan) yang menjalankan & memeriksa
seluruh service lokal dari satu console window — sesuai permintaan: **"server bisa jalan
berbentuk exe tapi jalan di cmd"**.

## Menjalankan dari source (dev)

```bash
cd smb-server-launcher
dotnet run
```

Mode cek-saja tanpa menjalankan apa pun (§77 — SMB Doctor):
```bash
dotnet run -- doctor
```

## Build jadi .exe asli

```bash
dotnet publish -c Release -r win-x64 --self-contained false -p:PublishSingleFile=true -o publish
```

Hasil: `publish/SmbServerLauncher.exe` — double-click atau jalankan dari `cmd.exe`/PowerShell,
butuh [.NET 8 Runtime](https://dotnet.microsoft.com/download/dotnet/8.0) terpasang di mesin
target (framework-dependent, bukan self-contained, supaya ukurannya kecil — PC pengembang
sudah punya SDK-nya).

## Yang dilakukan (urutan §65)

1. Cek PostgreSQL (`127.0.0.1:5433`) — TCP murni, tidak berasumsi container "pasti jalan"
2. Cek Redis (`127.0.0.1:6380`)
3. Start `php artisan serve --port=8010` di `smb-api/`, tunggu `/api/v1/health` benar2 OK
4. Start `node ace serve` di `smb-gateway/`, tunggu `/health` benar2 OK
5. Lapor status Cloudflare Tunnel (UNKNOWN — PHASE 20 belum dibangun, bukan dipalsukan OK)

Log kedua service tetap terlihat live di console yang sama dengan prefix `[laravel]`/`[gateway]`.
Ctrl+C mematikan keduanya lewat `Process.Kill(entireProcessTree: true)` sebelum launcher keluar.

## Keterbatasan yang jujur dicatat

- **Deteksi root repo** otomatis (cari folder yang punya `smb-api/` + `smb-gateway/` sebagai
  sibling) — kalau launcher dipindah keluar dari struktur repo, dia akan berhenti dengan
  pesan jelas, bukan mencoba menebak path yang salah.
- **Cloudflare Tunnel** (PHASE 20): sekarang dicek struktural nyata lewat
  `CloudflareTunnelChecker` — binary `cloudflared` di PATH, `cloudflare/config.yml` sudah
  diisi (bukan placeholder template), dan (di Windows) service `cloudflared` benar2
  `RUNNING` via `sc query`. Ini BUKAN pengecekan konektivitas tunnel sungguhan (butuh akun
  Cloudflare asli Anda — lihat `cloudflare/README.md`) — kalau salah satu syarat di atas
  belum terpenuhi, dilaporkan `NOT_CONFIGURED`/`FAIL` dengan alasan spesifik, bukan `OK`
  yang dipalsukan (§66). **Catatan jujur**: kode C# ini ditulis & direview teliti di sesi
  ini tapi TIDAK bisa di-compile-check (`dotnet build`) karena tidak ada .NET SDK
  terinstal di container sesi ini — tolong jalankan `dotnet build` sendiri dan laporkan
  kalau ada error compile.
- **Shutdown Ctrl+C** memakai API standar .NET (`Process.Kill(entireProcessTree: true)`) dan
  terverifikasi lewat kode/dokumentasi resmi .NET, TAPI sesi development ini tidak bisa
  mengirim sinyal Ctrl+C nyata ke proses background (keterbatasan tool otomasi, bukan bug) —
  **tolong konfirmasi manual**: jalankan launcher, tekan Ctrl+C, pastikan window `php` dan
  `node` benar-benar tertutup (cek Task Manager), bukan cuma window launcher yang hilang.
- **Eksekusi `.exe` langsung** (bukan lewat `dotnet run`/`dotnet SmbServerLauncher.dll`)
  diblokir "Application Control policy" di mesin development sandbox ini secara spesifik —
  kemungkinan besar TIDAK terjadi di PC produksi Anda (kebijakan itu khas lingkungan
  terkunci/enterprise, bukan default Windows biasa), tapi **tolong konfirmasi**: jalankan
  `publish\SmbServerLauncher.exe` langsung dari `cmd.exe` di PC Anda dan laporkan kalau ada
  pesan error serupa.
