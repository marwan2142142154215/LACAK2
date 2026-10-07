<?php

namespace App\Services;

use App\Models\Device;
use Carbon\CarbonInterface;

/**
 * §6: status device WAJIB ditentukan server-side dari timestamp heartbeat terakhir —
 * TIDAK PERNAH dari klaim client. Threshold di sini konfigurasi awal yang reasonable;
 * bisa dipindah ke system_settings (tabel sudah ada dari PHASE 2) kalau admin perlu
 * mengubahnya tanpa deploy — belum dibutuhkan sekarang, dicatat sebagai TODO sengaja
 * (§66: tidak membangun UI setting yang belum ada konsumennya).
 */
class DeviceStatusResolver
{
    public const ONLINE_THRESHOLD_SECONDS = 90;
    public const DEGRADED_THRESHOLD_SECONDS = 300;

    public function resolve(?CarbonInterface $lastHeartbeatAt): string
    {
        if ($lastHeartbeatAt === null) {
            return 'UNKNOWN';
        }

        $ageSeconds = $lastHeartbeatAt->diffInSeconds(now());

        return match (true) {
            $ageSeconds <= self::ONLINE_THRESHOLD_SECONDS => 'ONLINE',
            $ageSeconds <= self::DEGRADED_THRESHOLD_SECONDS => 'DEGRADED',
            default => 'OFFLINE',
        };
    }

    /**
     * Dipanggil scheduled command (§6) untuk menurunkan status device yang berhenti
     * mengirim heartbeat TANPA menunggu device itu connect lagi untuk "memicu" update —
     * tanpa ini, device yang mati mendadak akan terlihat ONLINE selamanya di dashboard.
     */
    public function recomputeStaleStatuses(): int
    {
        $updated = 0;

        Device::query()
            ->where('is_active', true)
            ->whereNotIn('status', ['LOCKED'])
            ->chunkById(500, function ($devices) use (&$updated) {
                foreach ($devices as $device) {
                    $correctStatus = $this->resolve($device->last_heartbeat_at);
                    if ($device->status !== $correctStatus) {
                        $device->update(['status' => $correctStatus]);
                        $updated++;
                    }
                }
            });

        return $updated;
    }
}
