<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §110-114 — endpoint storage LOKAL untuk device_media (default, perangkat/server pemilik
 * produk sendiri — bukan DigitalOcean Spaces). Route di-generate via Laravel signed URL
 * (`URL::temporarySignedRoute`, middleware `signed`) sehingga device/browser bisa akses
 * TANPA perlu Sanctum token (sama seperti S3 presigned URL, §28) tapi tetap time-limited
 * dan tidak bisa dipalsukan (HMAC signature, bukan "security by obscurity").
 *
 * §113: tidak ada directory listing, nama file di-generate SERVER (DeviceCameraController),
 * path divalidasi strict (`assertSafePath`) supaya tidak bisa path traversal keluar root disk.
 */
class DeviceMediaTransferController extends Controller
{
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024; // §44: batas ukuran wajar untuk 1 foto JPEG.

    /** PUT (signed) — device upload bytes mentah langsung ke storage lokal. */
    public function upload(Request $request): JsonResponse
    {
        $path = $this->assertSafePath((string) $request->query('path', ''));

        $bytes = $request->getContent();
        if ($bytes === '' || $bytes === false) {
            return ApiResponse::error('Body upload kosong.', [], 422);
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            return ApiResponse::error('Ukuran file melebihi batas maksimum ('.self::MAX_UPLOAD_BYTES.' byte).', [], 422);
        }

        Storage::disk('smb_media')->put($path, $bytes);

        return ApiResponse::success('Upload diterima.', [
            'path' => $path,
            'size_bytes' => strlen($bytes),
        ]);
    }

    /** GET (signed) — baca kembali file untuk ditampilkan (dashboard/Master), §28. */
    public function download(Request $request): StreamedResponse|JsonResponse
    {
        $path = $this->assertSafePath((string) $request->query('path', ''));
        $disk = Storage::disk('smb_media');

        if (! $disk->exists($path)) {
            return ApiResponse::error('File tidak ditemukan di storage lokal.', [], 404);
        }

        $mimeType = $disk->mimeType($path) ?: 'application/octet-stream';

        return response()->streamDownload(
            function () use ($disk, $path) {
                echo $disk->get($path);
            },
            basename($path),
            ['Content-Type' => $mimeType],
            'inline',
        );
    }

    /** §44/§113: path HARUS di bawah "devices/" (digenerate server) & tidak boleh traversal. */
    private function assertSafePath(string $path): string
    {
        abort_if(
            $path === '' || str_contains($path, '..') || ! str_starts_with($path, 'devices/'),
            400,
            'Path storage tidak valid.',
        );

        return $path;
    }
}
