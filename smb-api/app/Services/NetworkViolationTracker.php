<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceNetworkViolation;
use App\Models\SiteNetworkPolicy;
use App\Models\TelegramAccount;
use Illuminate\Support\Facades\DB;

/**
 * §103-106 — state machine deteksi pelanggaran jaringan per device:
 *
 *   NORMAL -> VIOLATION (baru) -> kirim alert, catat alert_sent_at
 *   VIOLATION -> VIOLATION (masih)  -> update last_seen_at SAJA, JANGAN kirim alert lagi (§105 anti-spam)
 *   VIOLATION -> NORMAL -> tandai resolved_at (episode ditutup)
 *   NORMAL (lagi) -> VIOLATION (baru lagi) -> row BARU, alert BARU (§105: "kalau violation baru
 *     muncul lagi: alert baru")
 */
class NetworkViolationTracker
{
    public function __construct(private TelegramBotClient $telegram) {}

    public function record(Device $device, NetworkPolicyStatus $status, ?string $observedIp): ?DeviceNetworkViolation
    {
        return DB::transaction(function () use ($device, $status, $observedIp) {
            // §21-style row lock: dua heartbeat nyaris bersamaan (WS + HTTPS fallback) untuk
            // device yang sama tidak boleh keduanya membuat row violation baru / keduanya
            // mengirim alert duplikat.
            $open = DeviceNetworkViolation::query()
                ->where('device_id', $device->id)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->first();

            if ($status === NetworkPolicyStatus::ALLOWED) {
                if ($open) {
                    $open->forceFill(['resolved_at' => now()])->save();
                }

                return null;
            }

            if ($open) {
                $open->forceFill(['last_seen_at' => now()])->save();

                return $open;
            }

            $violation = DeviceNetworkViolation::create([
                'device_id' => $device->id,
                'site_id' => $device->site_id,
                'observed_ip' => $observedIp,
                'policy_status' => $status->value,
                'severity' => $status === NetworkPolicyStatus::BLOCKED ? 'HIGH' : 'WARNING',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);

            $this->sendAlert($device, $violation);
            $violation->forceFill(['alert_sent_at' => now()])->save();

            return $violation;
        });
    }

    /** §104/§109 — alert Telegram. TIDAK PERNAH menyertakan credential/token/OTP (§109). */
    private function sendAlert(Device $device, DeviceNetworkViolation $violation): void
    {
        $device->loadMissing('site');
        $policies = SiteNetworkPolicy::query()
            ->where('site_id', $device->site_id)
            ->where('is_active', true)
            ->pluck('value')
            ->implode(', ');

        $text = "⚠️ <b>PERINGATAN JARINGAN</b>\n\n".
            "Device:\n{$device->name}\n\n".
            "Site:\n{$device->site?->name}\n\n".
            "Status:\nNETWORK POLICY VIOLATION ({$violation->policy_status})\n\n".
            'IP terdeteksi:'."\n".($violation->observed_ip ?? 'tidak diketahui')."\n\n".
            'IP yang diizinkan:'."\n".($policies !== '' ? $policies : '(belum ada whitelist diisi)')."\n\n".
            'Waktu:'."\n".now()->toDateTimeString();

        // §106: HIGH severity untuk semua admin yang approved (sederhana & sesuai skala
        // proyek ini — belum ada per-Site notification targeting granular, dicatat sebagai
        // simplifikasi, bukan diklaim lengkap).
        TelegramAccount::query()
            ->where('status', 'APPROVED')
            ->get()
            ->each(fn (TelegramAccount $account) => $this->telegram->sendMessage($account->telegram_id, $text));
    }
}
