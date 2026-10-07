<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\DeviceHeartbeatRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceHeartbeat;
use App\Services\DeviceStatusResolver;
use App\Services\NetworkPolicyEvaluator;
use App\Services\NetworkViolationTracker;
use Illuminate\Http\JsonResponse;

/**
 * §45 HTTPS fallback — dipakai Android saat WebSocket tidak tersambung. Jalur utama
 * heartbeat tetap WebSocket (smb-gateway/AdonisJS, langsung tulis ke tabel yang sama).
 */
class DeviceHeartbeatController extends Controller
{
    public function __construct(
        private DeviceStatusResolver $statusResolver,
        private NetworkPolicyEvaluator $networkPolicy,
        private NetworkViolationTracker $violationTracker,
    ) {}

    public function store(DeviceHeartbeatRequest $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->attributes->get('device');

        $receivedAt = now();

        DeviceHeartbeat::create([
            'device_id' => $device->id,
            'battery_level' => $request->input('battery_level'),
            'network_type' => $request->input('network_type'),
            'connection_state' => $request->input('connection_state'),
            'app_version' => $request->input('app_version'),
            'android_version' => $request->input('android_version'),
            // §70: recorded_at = waktu di device kalau dikirim, kalau tidak pakai waktu
            // server sebagai fallback jujur (bukan dipalsukan "device bilang sekarang").
            'recorded_at' => $receivedAt,
            'received_at' => $receivedAt,
        ]);

        $status = $this->statusResolver->resolve($receivedAt);

        // §97-109: evaluasi jaringan SETIAP heartbeat — jalur server-side, bukan klaim device.
        $observedIp = $this->networkPolicy->resolveObservedIp($request);
        $networkStatus = $this->networkPolicy->evaluate($device, $observedIp);
        $this->violationTracker->record($device, $networkStatus, $observedIp);

        $device->forceFill([
            'last_heartbeat_at' => $receivedAt,
            'status' => $status,
            'last_seen_ip' => $observedIp,
            'app_version' => $request->input('app_version', $device->app_version),
            'android_version' => $request->input('android_version', $device->android_version),
        ])->save();

        return ApiResponse::success('Heartbeat diterima.', [
            'accepted' => true,
            'status' => $status,
            'network_status' => $networkStatus->value,
            'server_received_at' => $receivedAt->toIso8601String(),
        ]);
    }
}
