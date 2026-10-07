using System.Net.Sockets;

namespace SmbServerLauncher.Services;

/// <summary>
/// §52/§65/§77 — cek TCP murni, tidak berasumsi service "pasti jalan" hanya karena
/// container/proses pernah di-start. Dipakai untuk PostgreSQL &amp; Redis (keduanya
/// tidak punya HTTP health endpoint sendiri yang kita kontrol).
/// </summary>
public static class PortChecker
{
    public static async Task<bool> IsOpenAsync(string host, int port, int timeoutMs = 2000)
    {
        using var client = new TcpClient();
        try
        {
            var connectTask = client.ConnectAsync(host, port);
            var completed = await Task.WhenAny(connectTask, Task.Delay(timeoutMs));
            return completed == connectTask && client.Connected;
        }
        catch
        {
            return false;
        }
    }
}
