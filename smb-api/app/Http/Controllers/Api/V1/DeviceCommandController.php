<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreDeviceCommandRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceCommandDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceCommandController extends Controller
{
    public function __construct(private DeviceCommandDispatcher $dispatcher) {}

    /**
     * POST /api/v1/devices/{device}/commands — §19-22 command generik.
     */
    public function store(StoreDeviceCommandRequest $request, Device $device): JsonResponse
    {
        [$command, $wasReplay] = $this->dispatcher->dispatch(
            $device,
            $request->input('command_type'),
            $request->input('payload'),
            $request->input('idempotency_key'),
            $request->integer('expires_in_seconds', 120),
            $request->user(),
        );

        return ApiResponse::success(
            $wasReplay ? 'Command sudah pernah dibuat (idempotent replay).' : 'Command dibuat dan dikirim ke gateway.',
            $command,
            $wasReplay ? 200 : 201,
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
