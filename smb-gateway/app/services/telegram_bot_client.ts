import env from '#start/env'
import logger from '@adonisjs/core/services/logger'

/**
 * §29/§104/§109 — port tipis TypeScript dari App\Services\TelegramBotClient (Laravel).
 * Dipakai HANYA untuk alert network violation yang dideteksi di jalur heartbeat WS
 * (§6, jalur utama) — supaya alert tidak tertunda menunggu round-trip ke Laravel.
 * Gagal kirim TIDAK PERNAH melempar (§69/§70) — heartbeat device tetap harus sukses
 * diproses walau Telegram API down/belum dikonfigurasi.
 */
export async function sendTelegramMessage(chatId: number, text: string): Promise<boolean> {
  const token = env.get('TELEGRAM_BOT_TOKEN')
  if (!token) {
    logger.warn({ chatId }, 'telegram.send_message.not_configured')
    return false
  }

  try {
    const response = await fetch(`https://api.telegram.org/bot${token}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: chatId, text, parse_mode: 'HTML' }),
      signal: AbortSignal.timeout(5000),
    })

    if (!response.ok) {
      logger.warn({ chatId, status: response.status }, 'telegram.send_message.failed')
      return false
    }

    return true
  } catch (error) {
    logger.warn({ chatId, err: error }, 'telegram.send_message.failed')
    return false
  }
}
