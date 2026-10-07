<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\TelegramCommandHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §29 — webhook PUBLIK (dipanggil server Telegram, bukan user kita), diamankan dengan
 * secret token di header (bukan Sanctum) — lihat TELEGRAM_WEBHOOK_SECRET & cara pasang
 * webhook lewat setWebhook?secret_token=... di Telegram Bot API.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(private TelegramCommandHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $expected = config('services.telegram.webhook_secret');
        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! $expected || $provided !== $expected) {
            return ApiResponse::error('Webhook secret tidak valid.', [], 403);
        }

        $message = $request->input('message');

        if (is_array($message)) {
            $this->handler->handle($message);
        }

        // Telegram mengharapkan 200 cepat terlepas dari isi update — balasan sesungguhnya
        // dikirim async lewat TelegramBotClient::sendMessage di dalam handler.
        return ApiResponse::success('ok');
    }
}
