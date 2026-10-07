<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * §19-22 — satu-satunya jalur pembuatan device_commands. Dipakai langsung oleh
 * DeviceCommandController (command generik) DAN oleh controller khusus (lock/unlock/
 * location/camera, PHASE 13-16) supaya idempotency + notify-gateway konsisten di semua
 * jalur — tidak ada "jalan pintas" yang melewati row lock anti race-condition (§21).
 */
class DeviceCommandDispatcher
{
    public function __construct(private GatewayClient $gateway) {}

    /**
     * @return array{0: DeviceCommand, 1: bool} [$command, $wasIdempotentReplay]
     */
    /**
     * @param User $user Pengguna yang permission-nya dipakai untuk audit log (activity()->causedBy),
     *                    SELALU user manusia asli meski command datang lewat Telegram (§30) —
     *                    bot tidak punya identitas/permission sendiri, hanya meneruskan milik user.
     * @param string $createdByType 'USER' (dashboard/API langsung) atau 'TELEGRAM' (lewat bot, §29/§30).
     * @param string|null $createdById ID entity pemicu sesuai $createdByType. Default $user->id untuk
     *                                  'USER'. WAJIB diisi (telegram_account_id) untuk 'TELEGRAM' supaya
     *                                  audit trail bisa membedakan command dari dashboard vs bot.
     */
    public function dispatch(
        Device $device,
        string $commandType,
        ?array $payload,
        ?string $idempotencyKey,
        int $expiresInSeconds,
        User $user,
        string $createdByType = 'USER',
        ?string $createdById = null,
    ): array {
        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();
        $createdById ??= $user->id;

        [$command, $wasReplay] = DB::transaction(function () use ($device, $commandType, $payload, $idempotencyKey, $expiresInSeconds, $createdByType, $createdById) {
            $existing = DeviceCommand::query()
                ->where('device_id', $device->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [$existing, true];
            }

            $created = DeviceCommand::create([
                'device_id' => $device->id,
                'command_type' => $commandType,
                'payload' => $payload,
                'idempotency_key' => $idempotencyKey,
                'status' => 'PENDING',
                'created_by_type' => $createdByType,
                'created_by_id' => $createdById,
                'expires_at' => now()->addSeconds($expiresInSeconds),
            ]);

            return [$created, false];
        });

        if (! $wasReplay) {
            activity()
                ->causedBy($user)
                ->performedOn($command)
                ->withProperties(['device_id' => $device->id, 'command_type' => $commandType])
                ->log('device_command_created');

            $this->gateway->notifyCommandCreated($command->id);
        }

        return [$command, $wasReplay];
    }
}
