<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceCommandRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceCommandController extends Controller
{
    public function __construct(private GatewayClient $gateway) {}

    /**
     * POST /api/v1/devices/{device}/commands — §19-22.
     *
     * Idempotency (§22): kalau idempotency_key sudah pernah dipakai untuk device yang
     * SAMA, command LAMA dikembalikan (bukan dibuat ulang) — row lock (`lockForUpdate`)
     * mencegah race dua request bersamaan dengan key yang sama lolos berdua membuat
     * command duplikat (unique index di DB adalah jaring pengaman terakhir kalau ini
     * entah bagaimana terlewat).
     */
    public function store(StoreDeviceCommandRequest $request, Device $device): JsonResponse
    {
        $idempotencyKey = $request->input('idempotency_key') ?: (string) Str::uuid();

        $command = DB::transaction(function () use ($request, $device, $idempotencyKey) {
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
                'command_type' => $request->input('command_type'),
                'payload' => $request->input('payload'),
                'idempotency_key' => $idempotencyKey,
                'status' => 'PENDING',
                'created_by_type' => 'USER',
                'created_by_id' => $request->user()->id,
                'expires_at' => now()->addSeconds($request->integer('expires_in_seconds', 120)),
            ]);

            return [$created, false];
        });

        [$deviceCommand, $wasIdempotentReplay] = $command;

        if (! $wasIdempotentReplay) {
            activity()
                ->causedBy($request->user())
                ->performedOn($deviceCommand)
                ->withProperties(['device_id' => $device->id, 'command_type' => $deviceCommand->command_type])
                ->log('device_command_created');

            // §70: kegagalan notify TIDAK membuat request ini gagal — command tetap
            // valid di DB, hanya pengiriman real-time yang mungkin tertunda.
            $this->gateway->notifyCommandCreated($deviceCommand->id);
        }

        return ApiResponse::success(
            $wasIdempotentReplay ? 'Command sudah pernah dibuat (idempotent replay).' : 'Command dibuat dan dikirim ke gateway.',
            $deviceCommand,
            $wasIdempotentReplay ? 200 : 201,
        );
    }

    /**
     * GET /api/v1/devices/{device}/commands — riwayat command (§48 command history).
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.view', Device::class);

        $commands = DeviceCommand::query()
            ->where('device_id', $device->id)
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Riwayat command.', $commands);
    }

    /**
     * GET /api/v1/devices/{device}/commands/{command} — detail + log transisi status.
     */
    public function show(Device $device, DeviceCommand $command): JsonResponse
    {
        $this->authorize('devices.view', Device::class);

        abort_if($command->device_id !== $device->id, 404);

        return ApiResponse::success('Detail command.', $command->load('logs'));
    }
}
