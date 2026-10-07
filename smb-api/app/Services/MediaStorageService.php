<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * §110-114 revisi prompt — StorageService abstraction. Device media (foto kamera) WAJIB
 * bisa pindah antara storage lokal (default, perangkat/server pemilik produk sendiri) dan
 * DigitalOcean Spaces (opsional, cloud) HANYA lewat ganti `.env` (`SMB_MEDIA_DISK`),
 * tanpa menyentuh controller/model (§59/§110).
 *
 * - Lokal (`smb_media`): tidak ada "presigned PUT URL" S3 asli — diganti dengan Laravel
 *   signed route (URL::temporarySignedRoute), yang punya properti keamanan setara: URL
 *   time-limited + tidak bisa dipalsukan (HMAC signature), device tetap upload LANGSUNG
 *   ke server (bukan lewat Laravel app logic tambahan) persis seperti alur Spaces (§28
 *   diagram tidak berubah secara konsep, hanya endpoint tujuannya).
 * - Spaces: tetap memakai S3 presigned URL asli (kode PHASE 16 yang sudah ada, tidak diubah).
 */
class MediaStorageService
{
    public function diskName(): string
    {
        return (string) config('filesystems.default_media_disk', 'smb_media');
    }

    public function isLocal(): bool
    {
        return $this->diskName() !== 'spaces';
    }

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    public function uploadTarget(string $storagePath, int $expiresInMinutes = 10): array
    {
        if ($this->isLocal()) {
            $url = URL::temporarySignedRoute(
                'devices.media.upload',
                now()->addMinutes($expiresInMinutes),
                ['path' => $storagePath],
            );

            return ['url' => $url, 'headers' => []];
        }

        // §66/§79: Flysystem/S3 presign TIDAK memvalidasi credential secara nyata — ia hanya
        // menghitung signature HMAC lokal, jadi temporaryUploadUrl() SUKSES TANPA EXCEPTION
        // walau DO_SPACES_KEY/SECRET kosong (baru gagal nanti saat device benar2 upload).
        // Dicek eksplisit di sini supaya controller bisa menolak dengan 503 jujur SEBELUM
        // command dibuat — bukan mengandalkan exception yang ternyata tidak pernah terjadi.
        if (blank(config('filesystems.disks.spaces.key')) || blank(config('filesystems.disks.spaces.secret'))) {
            throw new RuntimeException('DigitalOcean Spaces belum dikonfigurasi (DO_SPACES_KEY/SECRET kosong).');
        }

        $upload = Storage::disk('spaces')->temporaryUploadUrl($storagePath, now()->addMinutes($expiresInMinutes));

        return ['url' => $upload['url'], 'headers' => $upload['headers'] ?? []];
    }

    /** §28/§113: signed URL sementara untuk baca — null jujur kalau file belum ada (§66/§79). */
    public function readUrl(string $storagePath, int $expiresInMinutes = 10): ?string
    {
        if ($this->isLocal()) {
            if (! Storage::disk('smb_media')->exists($storagePath)) {
                return null;
            }

            return URL::temporarySignedRoute(
                'devices.media.download',
                now()->addMinutes($expiresInMinutes),
                ['path' => $storagePath],
            );
        }

        if (! Storage::disk('spaces')->exists($storagePath)) {
            return null;
        }

        try {
            return Storage::disk('spaces')->temporaryUrl($storagePath, now()->addMinutes($expiresInMinutes));
        } catch (Throwable $e) {
            // Driver/konfigurasi tidak mendukung temporary URL (misal Storage::fake() saat
            // test) — jujur null, bukan URL permanen yang tidak aman sebagai "solusi" (§66).
            return null;
        }
    }
}
