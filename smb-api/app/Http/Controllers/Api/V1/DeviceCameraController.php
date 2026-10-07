<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\StoreCameraRequestRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\DeviceMedia;
use App\Services\DeviceCommandDispatcher;
use App\Services\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * §27/§28/§110-114 — Camera capture. Device TIDAK upload lewat Laravel/AdonisJS (foto bisa
 * besar) — Laravel hanya menyiapkan URL upload (lokal signed URL ATAU presigned Spaces URL,
 * lewat MediaStorageService) dan menitipkannya di payload command, device upload LANGSUNG
 * ke tujuan storage, lalu ack SUCCESS dengan metadata hasil (pola sama dengan Location,
 * PHASE 15). Default storage = lokal (perangkat/server pemilik produk sendiri, §110).
 */
class DeviceCameraController extends Controller
{
    public function __construct(
        private DeviceCommandDispatcher $dispatcher,
        private MediaStorageService $storage,
    ) {}

    public function request(StoreCameraRequestRequest $request, Device $device): JsonResponse
    {
        $storagePath = sprintf('devices/%s/media/%s.jpg', $device->id, (string) Str::uuid());

        try {
            $upload = $this->storage->uploadTarget($storagePath);
        } catch (Throwable $e) {
            // §66/§69: jujur kalau storage tujuan (Spaces, kalau SMB_MEDIA_DISK=spaces) belum
            // terkonfigurasi — jangan buat command yang device tidak akan pernah bisa
            // selesaikan (tidak ada tempat upload). Storage lokal (default) tidak pernah masuk
            // sini kecuali APP_KEY belum di-generate (signed URL butuh APP_KEY, kasus setup awal).
            return ApiResponse::error(
                'Storage media belum terkonfigurasi dengan benar di server. '.
                ($this->storage->isLocal()
                    ? 'Pastikan APP_KEY sudah di-generate.'
                    : 'Isi DO_SPACES_KEY/SECRET di .env sebelum mengirim perintah camera.'),
                [],
                503,
            );
        }

        [$command, $wasReplay] = $this->dispatcher->dispatch(
            $device,
            'CAMERA_REQUEST',
            [
                'camera_facing' => $request->input('camera_facing'),
                'upload_url' => $upload['url'],
                'upload_headers' => $upload['headers'] ?? [],
                'storage_path' => $storagePath,
            ],
            $request->input('idempotency_key'),
            $request->integer('expires_in_seconds', 120),
            $request->user(),
        );

        return ApiResponse::success(
            $wasReplay ? 'Permintaan camera sudah pernah dibuat.' : 'Permintaan camera dikirim ke device.',
            $command,
            $wasReplay ? 200 : 201,
        );
    }

    /**
     * GET /api/v1/devices/{device}/media — riwayat foto, paginated (§28/§48).
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('devices.camera', Device::class);

        $media = DeviceMedia::query()
            ->where('device_id', $device->id)
            ->orderByDesc('captured_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Riwayat media.', $media);
    }

    /**
     * GET /api/v1/devices/{device}/media/{media}/url — signed URL sementara (§28).
     */
    public function url(Device $device, DeviceMedia $media): JsonResponse
    {
        $this->authorize('devices.camera', Device::class);

        abort_if($media->device_id !== $device->id, 404);

        $url = $media->signedUrl();

        if (! $url) {
            return ApiResponse::error('File tidak ditemukan di storage (mungkin belum selesai upload atau sudah dihapus).', [], 404);
        }

        return ApiResponse::success('Signed URL dibuat.', ['url' => $url, 'expires_in_minutes' => 10]);
    }
}
