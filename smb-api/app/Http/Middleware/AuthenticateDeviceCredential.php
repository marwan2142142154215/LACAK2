<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceCredential;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * §43: device WAJIB authenticated dengan credential lengkap (device_id + public_token_id
 * + device_secret) — bukan device_id saja. Pola sama persis dengan verifikasi di
 * smb-gateway (AdonisJS) saat WebSocket connect, supaya HTTPS fallback (§45) punya
 * level keamanan yang identik, bukan "pintu belakang" yang lebih lemah.
 *
 * Device yang berhasil diautentikasi di-bind ke $request->attributes['device'].
 */
class AuthenticateDeviceCredential
{
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->input('device_id');
        $publicTokenId = $request->input('public_token_id');
        $deviceSecret = $request->input('device_secret');

        if (! $deviceId || ! $publicTokenId || ! $deviceSecret) {
            return ApiResponse::error('device_id, public_token_id, dan device_secret wajib diisi.', [], 401);
        }

        $credential = DeviceCredential::query()
            ->where('device_id', $deviceId)
            ->where('public_token_id', $publicTokenId)
            ->whereNull('revoked_at')
            ->first();

        if (! $credential || ! Hash::check($deviceSecret, $credential->credential_hash)) {
            activity()->withProperties(['device_id' => $deviceId, 'ip' => $request->ip()])
                ->log('device_heartbeat_auth_rejected');

            return ApiResponse::error('Kredensial device tidak valid atau sudah dicabut.', [], 401);
        }

        $device = Device::query()->where('id', $deviceId)->where('is_active', true)->first();

        if (! $device) {
            return ApiResponse::error('Device tidak aktif atau tidak ditemukan.', [], 401);
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
