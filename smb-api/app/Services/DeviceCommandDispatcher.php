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
    public function dispatch(
        Device $device,
        string $commandType,
        ?array $payload,
        ?string $idempotencyKey,
        int $expiresInSeconds,
        User $user,
    ): array {
        $idempotencyKey = $idempotencyKey ?: (string) Str::uuid();

        [$command, $wasReplay] = DB::transaction(function () use ($device, $commandType, $payload, $idempotencyKey, $expiresInSeconds, $user) {
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
                'created_by_type' => 'USER',
                'created_by_id' => $user->id,
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
