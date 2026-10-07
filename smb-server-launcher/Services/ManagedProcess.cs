using System.Diagnostics;

namespace SmbServerLauncher.Services;

/// <summary>
/// §21/§32/§65 — membungkus satu child process (php artisan serve / node ace serve)
/// supaya: (1) log-nya tetap terlihat live di console launcher dengan prefix jelas
/// servicenya apa, (2) bisa dimatikan BERSIH saat launcher di-Ctrl+C (§76 — server
/// restart test butuh shutdown yang benar, bukan proses zombie yang nyangkut).
/// </summary>
public sealed class ManagedProcess : IDisposable
{
    public string Name { get; }
    private readonly Process _process;
    private bool _stopRequested;

    private ManagedProcess(string name, Process process)
    {
        Name = name;
        _process = process;
    }

    public static ManagedProcess Start(string name, string fileName, string arguments, string workingDirectory, ConsoleColor tagColor)
    {
        var psi = new ProcessStartInfo
        {
            FileName = fileName,
            Arguments = arguments,
            WorkingDirectory = workingDirectory,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
            CreateNoWindow = true,
        };

        var process = new Process { StartInfo = psi, EnableRaisingEvents = true };
        var managed = new ManagedProcess(name, process);

        process.OutputDataReceived += (_, e) => managed.PrintLine(e.Data, tagColor);
        process.ErrorDataReceived += (_, e) => managed.PrintLine(e.Data, ConsoleColor.Red);
        process.Exited += (_, _) =>
        {
            if (!managed._stopRequested)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine($"[{name}] berhenti TANPA DIMINTA (exit code {process.ExitCode}). Service ini TIDAK lagi berjalan.");
                Console.ResetColor();
            }
        };

        process.Start();
        process.BeginOutputReadLine();
        process.BeginErrorReadLine();

        return managed;
    }

    private void PrintLine(string? line, ConsoleColor color)
    {
        if (line is null) return;
        Console.ForegroundColor = color;
        Console.WriteLine($"[{Name}] {line}");
        Console.ResetColor();
    }

    public bool IsRunning => !_process.HasExited;

    public void Stop()
    {
        _stopRequested = true;
        if (_process.HasExited) return;

        try
        {
            // Kill(true) mematikan seluruh process tree (artisan serve/node ace serve
            // kadang spawn child process sendiri) — tanpa ini bisa ada proses nyangkut
            // setelah launcher ditutup (§76 real production test: server restart bersih).
            _process.Kill(entireProcessTree: true);
            _process.WaitForExit(5000);
        }
        catch
        {
            // Proses mungkin sudah exit duluan di antara check dan Kill — aman diabaikan.
        }
    }

    public void Dispose() => Stop();
}
