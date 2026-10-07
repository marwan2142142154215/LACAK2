<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\VerifyDeviceOtpRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceOtp;
use App\Services\DeviceCommandDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * §24 — kanal unlock SELF-SERVICE: device user yang terkunci & tidak punya akses admin
 * menghubungi admin via telepon, admin generate OTP di sini, membacakannya, device user
 * mengetik OTP langsung di layar lock (§23 LockActivity, Android). Endpoint verify PUBLIK
 * dengan sengaja — satu-satunya "otorisasi" adalah OTP itu sendiri, dilindungi hashing +
 * attempt limit + rate limit + short expiry (§39/§57), BUKAN diasumsikan aman tanpa itu.
 */
class DeviceOtpController extends Controller
{
    public function __construct(private DeviceCommandDispatcher $dispatcher) {}

    /**
     * POST /api/v1/devices/{device}/otp — admin generate (permission devices.unlock).
     * Kode plaintext HANYA tampil di response ini, sekali (§39 — tidak disimpan plaintext).
     */
    public function store(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.unlock', Device::class);

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $otp = new DeviceOtp([
            'device_id' => $device->id,
            'purpose' => 'UNLOCK',
            'expires_at' => now()->addMinutes(5),
            'max_attempts' => 5,
            'requested_by' => $request->user()->id,
        ]);
        $otp->forceFill(['otp_hash' => Hash::make($plainCode)]);
        $otp->save();

        activity()->causedBy($request->user())->performedOn($otp)
            ->withProperties(['device_id' => $device->id])
            ->log('device_otp_generated');

        return ApiResponse::success('OTP dibuat. Kode ini hanya ditampilkan sekali — sampaikan ke pemilik device secara aman.', [
            'code' => $plainCode,
            'expires_at' => $otp->expires_at,
            'max_attempts' => $otp->max_attempts,
        ], 201);
    }

    /**
     * POST /api/v1/devices/{device}/otp/verify — PUBLIK, throttle 'device-otp-verify'.
     */
    public function verify(VerifyDeviceOtpRequest $request, Device $device): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $device) {
            $otp = DeviceOtp::query()
                ->where('device_id', $device->id)
                ->where('purpose', 'UNLOCK')
                ->whereNull('used_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return ['error' => ['Tidak ada OTP aktif untuk device ini.', 404]];
            }

            if ($otp->isExpired()) {
                return ['error' => ['OTP sudah kedaluwarsa. Minta admin membuat OTP baru.', 422]];
            }

            if ($otp->attemptsExhausted()) {
                return ['error' => ['OTP sudah melebihi batas percobaan. Minta admin membuat OTP baru.', 422]];
            }

            if (! Hash::check($request->input('code'), $otp->otp_hash)) {
                $otp->increment('attempt_count');

                activity()->performedOn($otp)->withProperties(['device_id' => $device->id])->log('device_otp_attempt_failed');

                $remaining = max(0, $otp->max_attempts - $otp->attempt_count);

                return ['error' => ["Kode OTP salah. Percobaan tersisa: {$remaining}.", 401]];
            }

            // §24: invalidated after successful use — single-use ditegakkan di sini.
            $otp->forceFill(['used_at' => now()])->save();

            return ['otp' => $otp];
        });

        if (isset($result['error'])) {
            [$message, $status] = $result['error'];

            return ApiResponse::error($message, [], $status);
        }

        /** @var DeviceOtp $otp */
        $otp = $result['otp'];
        $adminUser = $otp->requestedBy; // admin yang generate OTP ini dicatat sebagai causer command unlock

        [$command] = $this->dispatcher->dispatch($device, 'UNLOCK', null, null, 60, $adminUser);

        activity()->performedOn($otp)->withProperties(['device_id' => $device->id])->log('device_otp_verified');

        return ApiResponse::success('OTP valid. Perintah unlock dikirim ke device.', ['command_id' => $command->id]);
    }
}
