<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceLocation;
use App\Services\DeviceCommandDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §25/§26 — Location request lewat command broker (sama seperti Lock/Unlock, PHASE 13).
 * Hasil lokasi NYATA ditulis oleh AdonisJS langsung saat device ack SUCCESS dengan
 * payload result (lihat docs/websocket.md) — endpoint di sini hanya REQUEST & BACA.
 */
class DeviceLocationController extends Controller
{
    public function __construct(private DeviceCommandDispatcher $dispatcher) {}

    public function request(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.location', Device::class);

        [$command, $wasReplay] = $this->dispatcher->dispatch(
            $device,
            'LOCATION_REQUEST',
            null,
            $request->input('idempotency_key'),
            $request->integer('expires_in_seconds', 60),
            $request->user(),
        );

        return ApiResponse::success(
            $wasReplay ? 'Permintaan lokasi sudah pernah dibuat.' : 'Permintaan lokasi dikirim ke device.',
            $command,
            $wasReplay ? 200 : 201,
        );
    }

    /**
     * GET /api/v1/devices/{device}/locations — riwayat lokasi, paginated (§26).
     * Retention (§71) belum ada job pembersih otomatis — dicatat sebagai TODO,
     * dikonfigurasi via system_settings saat benar2 dibutuhkan (§66).
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.location', Device::class);

        $locations = DeviceLocation::query()
            ->where('device_id', $device->id)
            ->orderByDesc('recorded_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Riwayat lokasi.', $locations);
    }

    /**
     * GET /api/v1/devices/{device}/locations/latest — lokasi terakhir (dashboard map, §48).
     */
    public function latest(Device $device): JsonResponse
    {
        $this->authorize('devices.location', Device::class);

        $location = DeviceLocation::query()
            ->where('device_id', $device->id)
            ->orderByDesc('recorded_at')
            ->first();

        if (! $location) {
            return ApiResponse::error('Belum ada data lokasi untuk device ini.', [], 404);
        }

        return ApiResponse::success('Lokasi terakhir.', $location);
    }
}
