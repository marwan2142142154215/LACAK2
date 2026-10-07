<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * §3 — klien HTTP internal Laravel -> AdonisJS. Laravel TIDAK mengirim command langsung
 * ke device (tidak punya koneksi WebSocket) — hanya memberi tahu AdonisJS "ada command
 * baru siap dikirim", AdonisJS yang benar-benar push via socket (§19).
 */
class GatewayClient
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.gateway.url'), '/');
        $this->secret = (string) config('services.gateway.secret');
    }

    /**
     * Memberitahu AdonisJS bahwa command baru siap dikirim. Kalau gagal (gateway down),
     * command TETAP tersimpan di DB dengan status PENDING — tidak hilang, device akan
     * mengambilnya lewat command sync HTTPS polling (§45, PHASE 12 lanjutan) atau saat
     * AdonisJS retry/poll pending commands sendiri. Kegagalan di sini TIDAK dianggap fatal
     * bagi request caller (§70 — tapi tetap dilog supaya terlihat di monitoring).
     */
    public function notifyCommandCreated(string $commandId): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['x-internal-secret' => $this->secret])
                ->post("{$this->baseUrl}/api/v1/internal/commands/dispatch", [
                    'command_id' => $commandId,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('gateway.notify_command_created.failed', [
                'command_id' => $commandId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
