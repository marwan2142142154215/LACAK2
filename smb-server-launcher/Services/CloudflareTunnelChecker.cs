using System.Diagnostics;

namespace SmbServerLauncher.Services;

/// <summary>
/// §35/§77 — PHASE 20. Mengganti placeholder "UNKNOWN" sebelumnya dengan pengecekan
/// STRUKTURAL nyata: binary `cloudflared` ada di PATH, `cloudflare/config.yml` sudah
/// diisi (bukan template placeholder), dan (di Windows) service `cloudflared` benar-benar
/// berjalan. Ini BUKAN pengecekan konektivitas tunnel yang sesungguhnya — itu butuh akun
/// Cloudflare asli pemilik produk (§66: jangan klaim OK untuk hal yang tidak bisa
/// diverifikasi dari sini) — lihat cloudflare/README.md untuk langkah manual yang
/// membuat baris ini berubah dari "NOT_CONFIGURED" menjadi "OK" sungguhan.
/// </summary>
public static class CloudflareTunnelChecker
{
    public static async Task<(bool? Ok, string Detail)> CheckAsync(string repoRoot)
    {
        var configPath = Path.Combine(repoRoot, "cloudflare", "config.yml");
        if (!File.Exists(configPath))
        {
            return (false, "cloudflare/config.yml tidak ditemukan — lihat cloudflare/README.md.");
        }

        var configContent = await File.ReadAllTextAsync(configPath);
        if (configContent.Contains("<ISI_TUNNEL_ID_DARI_CLOUDFLARED_TUNNEL_CREATE>"))
        {
            return (false, "config.yml masih template (tunnel ID belum diisi) — lihat cloudflare/README.md langkah 1-5.");
        }

        var binaryFound = await FindCloudflaredBinaryAsync();
        if (!binaryFound)
        {
            return (false, "config.yml sudah diisi, tapi binary 'cloudflared' tidak ditemukan di PATH — install dulu (cloudflare/README.md langkah 2).");
        }

        if (!OperatingSystem.IsWindows())
        {
            // §88: jujur soal batas platform — launcher ini ditargetkan Windows (§32/§33),
            // pengecekan service Windows tidak berlaku di OS lain.
            return (null, "Binary & config ditemukan; status service tidak bisa dicek di platform non-Windows.");
        }

        var serviceRunning = await IsWindowsServiceRunningAsync("cloudflared");
        return serviceRunning
            ? (true, "OK — service Windows 'cloudflared' berjalan.")
            : (false, "Binary & config ada, tapi service Windows 'cloudflared' belum di-install/jalan — jalankan 'cloudflared service install' (cloudflare/README.md langkah 6).");
    }

    private static async Task<bool> FindCloudflaredBinaryAsync()
    {
        var command = OperatingSystem.IsWindows() ? "where" : "which";
        var (exitCode, _) = await RunAsync(command, "cloudflared");
        return exitCode == 0;
    }

    private static async Task<bool> IsWindowsServiceRunningAsync(string serviceName)
    {
        var (exitCode, output) = await RunAsync("sc", $"query {serviceName}");
        return exitCode == 0 && output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase);
    }

    private static async Task<(int ExitCode, string Output)> RunAsync(string fileName, string arguments)
    {
        try
        {
            var startInfo = new ProcessStartInfo
            {
                FileName = fileName,
                Arguments = arguments,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            using var process = Process.Start(startInfo);
            if (process is null) return (-1, string.Empty);

            var output = await process.StandardOutput.ReadToEndAsync();
            await process.WaitForExitAsync();
            return (process.ExitCode, output);
        }
        catch
        {
            // Perintah tidak ditemukan sama sekali (bukan Windows, atau PATH rusak) — jujur
            // dilaporkan sebagai "tidak ditemukan" lewat exit code non-zero, bukan exception
            // yang menjatuhkan seluruh launcher (§52 error handling).
            return (-1, string.Empty);
        }
    }
}
