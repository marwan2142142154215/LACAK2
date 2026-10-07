<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * §29/§30 — klien tipis ke Telegram Bot API. SENGAJA tidak memakai SDK pihak ketiga —
 * cuma 1 method (sendMessage) yang dipakai, HTTP langsung lebih mudah diaudit & ditest.
 * Gagal kirim TIDAK PERNAH melempar exception ke caller (§69/§70 — kegagalan jujur,
 * dicatat, tapi tidak membuat command/akun gagal diproses hanya karena balasan chat gagal).
 */
class TelegramBotClient
{
    public function sendMessage(int $chatId, string $text): bool
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            Log::warning('telegram.send_message.not_configured', ['chat_id' => $chatId]);

            return false;
        }

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);

            if (! $response->successful()) {
                Log::warning('telegram.send_message.failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('telegram.send_message.exception', ['chat_id' => $chatId, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
