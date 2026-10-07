namespace SmbServerLauncher.Services;

/// <summary>§52/§77 — cek endpoint /health nyata milik smb-api &amp; smb-gateway, bukan
/// hanya cek proses "masih hidup" (proses bisa hidup tapi gagal connect DB, dst).</summary>
public static class HttpHealthChecker
{
    private static readonly HttpClient Client = new() { Timeout = TimeSpan.FromSeconds(3) };

    public static async Task<(bool Ok, string Detail)> CheckAsync(string url)
    {
        try
        {
            var response = await Client.GetAsync(url);
            var body = await response.Content.ReadAsStringAsync();
            return response.IsSuccessStatusCode
                ? (true, "OK")
                : (false, $"HTTP {(int)response.StatusCode}: {Truncate(body)}");
        }
        catch (Exception ex)
        {
            return (false, ex.Message);
        }
    }

    private static string Truncate(string s) => s.Length > 120 ? s[..120] + "…" : s;
}
