using SmbServerLauncher.Services;

// §32/§33/§65/§77 — SMB Server Launcher.
// Dijalankan sebagai .exe asli (dotnet publish -> single native console binary),
// BUKAN script .bat yang disamarkan. Dibuka dari cmd/PowerShell/double-click, log
// seluruh service tetap TERLIHAT di console yang sama (§32 requirement eksplisit:
// "server bisa jalan berbentuk exe tapi jalan di cmd").

var repoRoot = FindRepoRoot(AppContext.BaseDirectory)
    ?? FindRepoRoot(Directory.GetCurrentDirectory())
    ?? throw new InvalidOperationException(
        "Tidak bisa menemukan root repo (folder yang berisi smb-api/ dan smb-gateway/). " +
        "Jalankan launcher ini dari dalam repo, atau salin ke lokasi yang masih di dalam struktur repo.");

var smbApiPath = Path.Combine(repoRoot, "smb-api");
var smbGatewayPath = Path.Combine(repoRoot, "smb-gateway");

// §DEC-004: port 8000 BUKAN dipakai sengaja — port itu bentrok dengan container
// Docker proyek lain di mesin ini ("apk-api-1") yang kebetulan punya health endpoint
// mirip (HTTP 200 + "success":true), menyebabkan false-positive "OK" padahal itu
// bukan Laravel kita. 8010 dipakai konsisten di launcher, Android BuildConfig, dan docs.
const string apiHealthUrl = "http://127.0.0.1:8010/api/v1/health";
const string gatewayHealthUrl = "http://127.0.0.1:3334/health";

if (args.Contains("doctor"))
{
    await RunDoctorAsync();
    return;
}

await RunLauncherAsync();
return;

// ---------------------------------------------------------------------------

async Task RunLauncherAsync()
{
    PrintBanner();

    Console.WriteLine();
    Console.WriteLine("=== Tahap 1/5: PostgreSQL ===");
    var pgOk = await PortChecker.IsOpenAsync("127.0.0.1", 5433);
    PrintCheck("PostgreSQL (127.0.0.1:5433)", pgOk, pgOk ? "OK" : "Tidak terdengar — jalankan 'docker compose up -d' di root repo dulu.");

    Console.WriteLine();
    Console.WriteLine("=== Tahap 2/5: Redis ===");
    var redisOk = await PortChecker.IsOpenAsync("127.0.0.1", 6380);
    PrintCheck("Redis (127.0.0.1:6380)", redisOk, redisOk ? "OK" : "Tidak terdengar — jalankan 'docker compose up -d' di root repo dulu.");

    if (!pgOk || !redisOk)
    {
        PrintSystemStatus(false, "Database/cache belum siap — service lain tidak dijalankan.");
        Console.WriteLine();
        Console.WriteLine("Jalankan ini dulu, lalu coba lagi:");
        Console.WriteLine($"    cd \"{repoRoot}\"");
        Console.WriteLine("    docker compose up -d");
        return;
    }

    Console.WriteLine();
    Console.WriteLine("=== Tahap 3/5: Laravel (smb-api) ===");
    using var laravel = ManagedProcess.Start("laravel", "php", "artisan serve --host=127.0.0.1 --port=8010", smbApiPath, ConsoleColor.Cyan);
    var laravelHealthy = await WaitForHealthAsync(apiHealthUrl, "Laravel", maxAttempts: 15);

    Console.WriteLine();
    Console.WriteLine("=== Tahap 4/5: AdonisJS (smb-gateway) ===");
    using var gateway = ManagedProcess.Start("gateway", "node", "ace serve", smbGatewayPath, ConsoleColor.Magenta);
    var gatewayHealthy = await WaitForHealthAsync(gatewayHealthUrl, "AdonisJS", maxAttempts: 15);

    Console.WriteLine();
    Console.WriteLine("=== Tahap 5/5: Cloudflare Tunnel ===");
    var (tunnelOk, tunnelDetail) = await CloudflareTunnelChecker.CheckAsync(repoRoot);
    PrintCheck("Cloudflare Tunnel", tunnelOk, tunnelOk == false ? $"{tunnelDetail} Server tetap jalan LOKAL tanpa tunnel (§64)." : tunnelDetail);

    Console.WriteLine();
    var allOk = laravelHealthy && gatewayHealthy;
    PrintSystemStatus(allOk, allOk
        ? "Laravel + AdonisJS sehat. Akses lokal: http://127.0.0.1:8010 (API), ws://127.0.0.1:3334 (gateway)."
        : "Satu atau lebih service gagal health check — lihat log di atas. Launcher TETAP membiarkan proses yang sudah start berjalan supaya log bisa dibaca.");

    Console.WriteLine();
    Console.WriteLine("Tekan Ctrl+C untuk menghentikan semua service dengan aman.");

    var shutdown = new TaskCompletionSource();
    Console.CancelKeyPress += (_, e) =>
    {
        e.Cancel = true; // tangani sendiri, jangan biarkan proses mati mendadak
        shutdown.TrySetResult();
    };
    await shutdown.Task;

    Console.WriteLine();
    Console.WriteLine("Menghentikan service (urutan kebalikan dari startup, §65)...");
    gateway.Stop();
    laravel.Stop();
    Console.WriteLine("Semua service dihentikan. Sampai jumpa.");
}

async Task RunDoctorAsync()
{
    // §77 — SMB Doctor: HANYA memeriksa, tidak menjalankan apa pun. Dipakai saat
    // launcher utama sudah jalan di proses/terminal lain dan ingin verifikasi cepat.
    Console.WriteLine("=== SMB Doctor ===");
    Console.WriteLine();

    var pgOk = await PortChecker.IsOpenAsync("127.0.0.1", 5433);
    PrintDoctorLine("PostgreSQL", pgOk);

    var redisOk = await PortChecker.IsOpenAsync("127.0.0.1", 6380);
    PrintDoctorLine("Redis", redisOk);

    var (laravelOk, laravelDetail) = await HttpHealthChecker.CheckAsync(apiHealthUrl);
    PrintDoctorLine("Laravel (smb-api)", laravelOk, laravelDetail);

    var (gatewayOk, gatewayDetail) = await HttpHealthChecker.CheckAsync(gatewayHealthUrl);
    PrintDoctorLine("AdonisJS (smb-gateway)", gatewayOk, gatewayDetail);

    var (tunnelOk, tunnelDetail) = await CloudflareTunnelChecker.CheckAsync(repoRoot);
    PrintDoctorLine("Cloudflare Tunnel", tunnelOk, tunnelDetail);

    Console.WriteLine();
    var allOk = pgOk && redisOk && laravelOk && gatewayOk;
    Console.WriteLine(allOk ? "SYSTEM READY" : "SYSTEM DEGRADED");
}

async Task<bool> WaitForHealthAsync(string url, string label, int maxAttempts)
{
    for (var attempt = 1; attempt <= maxAttempts; attempt++)
    {
        await Task.Delay(1000);
        var (ok, detail) = await HttpHealthChecker.CheckAsync(url);
        if (ok)
        {
            PrintCheck($"{label} health", true, "OK");
            return true;
        }
        if (attempt == maxAttempts)
        {
            PrintCheck($"{label} health", false, $"Gagal setelah {maxAttempts}s: {detail}");
            return false;
        }
    }
    return false;
}

string? FindRepoRoot(string start)
{
    var dir = new DirectoryInfo(start);
    while (dir is not null)
    {
        if (Directory.Exists(Path.Combine(dir.FullName, "smb-api")) &&
            Directory.Exists(Path.Combine(dir.FullName, "smb-gateway")))
        {
            return dir.FullName;
        }
        dir = dir.Parent;
    }
    return null;
}

void PrintBanner()
{
    Console.ForegroundColor = ConsoleColor.Green;
    Console.WriteLine("========================================");
    Console.WriteLine("  SMB Server Launcher");
    Console.WriteLine("========================================");
    Console.ResetColor();
    Console.WriteLine($"Repo root: {repoRoot}");
}

void PrintCheck(string label, bool? ok, string detail)
{
    Console.ForegroundColor = ok switch { true => ConsoleColor.Green, false => ConsoleColor.Red, null => ConsoleColor.Yellow };
    Console.Write(ok switch { true => "[OK]   ", false => "[FAIL] ", null => "[????] " });
    Console.ResetColor();
    Console.WriteLine($"{label} — {detail}");
}

void PrintDoctorLine(string label, bool? ok, string? detail = null)
{
    Console.ForegroundColor = ok switch { true => ConsoleColor.Green, false => ConsoleColor.Red, null => ConsoleColor.Yellow };
    Console.Write(ok switch { true => "[OK]   ", false => "[FAIL] ", null => "[????] " });
    Console.ResetColor();
    Console.WriteLine(detail is null ? label : $"{label} — {detail}");
}

void PrintSystemStatus(bool ready, string detail)
{
    Console.ForegroundColor = ready ? ConsoleColor.Green : ConsoleColor.Yellow;
    Console.WriteLine(ready ? "SYSTEM READY" : "SYSTEM DEGRADED");
    Console.ResetColor();
    Console.WriteLine(detail);
}
