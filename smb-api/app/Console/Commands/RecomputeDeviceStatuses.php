<?php

namespace App\Console\Commands;

use App\Services\DeviceStatusResolver;
use Illuminate\Console\Command;

/**
 * §6: tanpa command ini, device yang berhenti kirim heartbeat (mati/offline) akan
 * TETAP terlihat ONLINE selamanya di dashboard sampai dia connect lagi — karena
 * status hanya di-update saat heartbeat MASUK. Command ini jalan periodik untuk
 * "menyusul" device yang diam, dijadwalkan di routes/console.php.
 */
class RecomputeDeviceStatuses extends Command
{
    protected $signature = 'smb:recompute-device-statuses';

    protected $description = 'Hitung ulang status ONLINE/DEGRADED/OFFLINE device berdasarkan last_heartbeat_at (§6)';

    public function handle(DeviceStatusResolver $resolver): int
    {
        $updated = $resolver->recomputeStaleStatuses();
        $this->info("Status {$updated} device diperbarui.");

        return self::SUCCESS;
    }
}
