<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\DeviceRegistrationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceRegistrationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DeviceRegistrationController extends Controller
{
    /**
     * POST /api/v1/devices/register — endpoint PUBLIK (device belum punya token).
     *
     * §17/§21: transaksi + row lock (`lockForUpdate`) WAJIB — tanpa ini, dua request
     * bersamaan dengan kode yang sama bisa lolos validasi "belum dipakai" sebelum salah
     * satunya sempat menandai used_at, menghasilkan DUA device teregistrasi dari SATU kode.
     */
    public function store(DeviceRegistrationRequest $request): JsonResponse
    {
        $codeHash = hash('sha256', $request->string('code')->toString());

        $result = DB::transaction(function () use ($request, $codeHash) {
            $registrationCode = DeviceRegistrationCode::query()
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if (! $registrationCode) {
                return ['error' => ['Registration code tidak ditemukan.', 404]];
            }

            if ($registrationCode->isRevoked()) {
                return ['error' => ['Registration code sudah dicabut.', 422]];
            }

            if ($registrationCode->isUsed()) {
                activity()->performedOn($registrationCode)->log('registration_code_reuse_rejected');

                return ['error' => ['Registration code sudah pernah dipakai.', 422]];
            }

            if ($registrationCode->isExpired()) {
                return ['error' => ['Registration code sudah kedaluwarsa.', 422]];
            }

            $device = Device::create([
                'site_id' => $registrationCode->site_id,
                'team_id' => $registrationCode->team_id,
                'name' => 'Device '.Str::upper(Str::random(6)),
                'status' => 'UNKNOWN',
                'is_managed' => (bool) data_get($request->input('capability_report'), 'device_owner', false),
                'android_api_level' => $request->input('android_api_level'),
                'android_version' => $request->input('android_version'),
                'app_version' => $request->input('app_version'),
                'manufacturer' => $request->input('manufacturer'),
                'model' => $request->input('model'),
                'capability_report' => $request->input('capability_report'),
                'registered_at' => now(),
                'registered_by' => $registrationCode->created_by,
                'is_active' => true,
            ]);

            // device_secret: bcrypt (BUKAN sha256) karena dibandingkan oleh AdonisJS via
            // bcrypt.compare() saat device connect WebSocket (§43) — harus sama algoritma
            // di kedua sisi. Beda dari registration code di atas yang pure lookup token.
            $deviceSecret = Str::random(48);

            DeviceCredential::create([
                'device_id' => $device->id,
                'credential_hash' => Hash::make($deviceSecret),
                'public_token_id' => (string) Str::uuid(),
                'issued_at' => now(),
            ]);

            $registrationCode->forceFill([
                'used_at' => now(),
                'used_by_device_id' => $device->id,
            ])->save();

            activity()
                ->performedOn($device)
                ->withProperties(['site_id' => $device->site_id, 'team_id' => $device->team_id])
                ->log('device_registered');

            return [
                'device' => $device,
                'public_token_id' => DeviceCredential::where('device_id', $device->id)->value('public_token_id'),
                'device_secret' => $deviceSecret,
            ];
        });

        if (isset($result['error'])) {
            [$message, $status] = $result['error'];

            return ApiResponse::error($message, [], $status);
        }

        // §18: device_secret HANYA ditampilkan di sini, sekali. Tidak pernah bisa
        // di-retrieve ulang setelah ini (hanya credential_hash yang tersimpan di DB).
        return ApiResponse::success('Device berhasil didaftarkan.', [
            'device_id' => $result['device']->id,
            'public_token_id' => $result['public_token_id'],
            'device_secret' => $result['device_secret'],
            'site_id' => $result['device']->site_id,
            'team_id' => $result['device']->team_id,
        ], 201);
    }
}
