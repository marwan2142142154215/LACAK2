<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §36/§48 — resource dasar Device (daftar+filter+detail), terpisah dari sub-resource
 * command/lock/otp/location/camera (PHASE 12-16) yang sudah ada.
 */
class DeviceController extends Controller
{
    /**
     * GET /api/v1/devices — §48 filter: site, team, status, android version, battery, last seen.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('devices.view', Device::class);

        $devices = Device::query()
            ->with(['site:id,name', 'team:id,name'])
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->input('site_id')))
            ->when($request->filled('team_id'), fn ($q) => $q->where('team_id', $request->input('team_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('android_api_level'), fn ($q) => $q->where('android_api_level', $request->input('android_api_level')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderByDesc('last_heartbeat_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Daftar device.', $devices);
    }

    /**
     * GET /api/v1/devices/overview — §48 kartu ringkasan dashboard.
     */
    public function overview(): JsonResponse
    {
        $this->authorize('devices.view', Device::class);

        $counts = Device::query()
            ->where('is_active', true)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success('Ringkasan device.', [
            'total' => $counts->sum(),
            'online' => $counts->get('ONLINE', 0),
            'degraded' => $counts->get('DEGRADED', 0),
            'offline' => $counts->get('OFFLINE', 0),
            'locked' => $counts->get('LOCKED', 0),
            'unknown' => $counts->get('UNKNOWN', 0),
        ]);
    }

    /**
     * GET /api/v1/devices/{device} — detail (§48).
     */
    public function show(Device $device): JsonResponse
    {
        $this->authorize('devices.view', Device::class);

        return ApiResponse::success('Detail device.', $device->load(['site', 'team']));
    }

    /**
     * PATCH /api/v1/devices/{device} — admin ubah nama/status aktif (bukan field server-owned
     * seperti status/last_heartbeat_at, itu dikendalikan AdonisJS/heartbeat, §6).
     */
    public function update(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.update', Device::class);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $device->update($validated);

        activity()->causedBy($request->user())->performedOn($device)->log('device_updated');

        return ApiResponse::success('Device berhasil diperbarui.', $device);
    }

    /**
     * DELETE /api/v1/devices/{device} — soft delete (§35), bukan hapus permanen.
     */
    public function destroy(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.delete', Device::class);

        $device->delete();

        activity()->causedBy($request->user())->performedOn($device)->log('device_deleted');

        return ApiResponse::success('Device berhasil dihapus.');
    }
}
