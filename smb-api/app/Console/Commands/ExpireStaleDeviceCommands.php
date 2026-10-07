<?php

namespace App\Console\Commands;

use App\Models\DeviceCommand;
use Illuminate\Console\Command;

/**
 * §21: "command expired tetap dieksekusi" WAJIB dicegah. Command yang masih
 * PENDING/QUEUED/SENT/DELIVERED/RECEIVED/EXECUTING tapi sudah lewat expires_at
 * ditandai EXPIRED di sini — device yang terlambat mengirim ack SUCCESS untuk
 * command yang sudah EXPIRED akan ditolak (state machine di AdonisJS, lihat
 * docs/websocket.md), bukan diterima begitu saja.
 */
class ExpireStaleDeviceCommands extends Command
{
    protected $signature = 'smb:expire-stale-commands';

    protected $description = 'Tandai EXPIRED command yang melewati expires_at tapi belum terminal (§21)';

    public function handle(): int
    {
        $nonTerminal = array_diff(DeviceCommand::STATUSES, DeviceCommand::TERMINAL_STATUSES);

        $count = DeviceCommand::query()
            ->whereIn('status', $nonTerminal)
            ->where('expires_at', '<', now())
            ->update(['status' => 'EXPIRED']);

        $this->info("{$count} command ditandai EXPIRED.");

        return self::SUCCESS;
    }
}
