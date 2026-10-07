<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Services\DeviceCommandDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §23/§24 — Lock & Unlock sebagai wrapper tipis di atas command broker (PHASE 12).
 * Tidak ada jalur langsung Admin -> Device: semuanya tetap lewat device_commands +
 * AdonisJS (§19). Unlock via OTP (§24, kanal terpisah untuk kondisi tanpa akses admin)
 * ditambahkan PHASE 14 — endpoint ini melayani kanal "SMB Master / Web Dashboard"
 * yang disebutkan §24 secara eksplisit sebagai jalur sah yang SAMA validnya dengan OTP.
 */
class DeviceLockController extends Controller
{
    public function __construct(private DeviceCommandDispatcher $dispatcher) {}

    public function lock(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.lock', Device::class);

        if ($device->status === 'LOCKED') {
            return ApiResponse::error('Device sudah dalam status LOCKED.', [], 422);
        }

        [$command, $wasReplay] = $this->dispatcher->dispatch(
            $device,
            'LOCK',
            // §23: pesan ditampilkan di layar device — bisa dikustomisasi per permintaan,
            // default sesuai teks yang diminta spesifikasi.
            ['message' => $request->input('message', 'Segera kembali ke tempat asal anda')],
            $request->input('idempotency_key'),
            $request->integer('expires_in_seconds', 60),
            $request->user(),
        );

        return ApiResponse::success(
            $wasReplay ? 'Perintah lock sudah pernah dibuat.' : 'Perintah lock dikirim ke device.',
            $command,
            $wasReplay ? 200 : 201,
        );
    }

    public function unlock(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.unlock', Device::class);

        [$command, $wasReplay] = $this->dispatcher->dispatch(
            $device,
            'UNLOCK',
            null,
            $request->input('idempotency_key'),
            $request->integer('expires_in_seconds', 60),
            $request->user(),
        );

        return ApiResponse::success(
            $wasReplay ? 'Perintah unlock sudah pernah dibuat.' : 'Perintah unlock dikirim ke device.',
            $command,
            $wasReplay ? 200 : 201,
        );
    }
}
