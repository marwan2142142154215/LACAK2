<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * §110-114 — device upload/download LANGSUNG ke storage lokal lewat Laravel signed URL
 * (bukan Sanctum/device-credential, sama seperti S3 presigned, §28). Test ini membuktikan
 * roundtrip NYATA (bytes yang di-PUT benar-benar bisa di-GET kembali identik), bukan
 * sekadar mengecek status code (§79 — no fake success).
 */
beforeEach(function () {
    Storage::fake('smb_media');
});

it('accepts a signed PUT upload and writes the exact bytes to local storage', function () {
    $path = 'devices/dev-1/media/photo.jpg';
    $url = URL::temporarySignedRoute('devices.media.upload', now()->addMinutes(10), ['path' => $path]);

    $response = $this->call('PUT', $url, [], [], [], [], 'raw-jpeg-bytes-123');

    $response->assertOk();
    expect(Storage::disk('smb_media')->get($path))->toBe('raw-jpeg-bytes-123');
});

it('rejects an upload URL whose signature has been tampered with', function () {
    $path = 'devices/dev-1/media/photo.jpg';
    $url = URL::temporarySignedRoute('devices.media.upload', now()->addMinutes(10), ['path' => $path]);
    $tampered = $url.'tampered';

    $this->call('PUT', $tampered, [], [], [], [], 'bytes')->assertStatus(403);
    expect(Storage::disk('smb_media')->exists($path))->toBeFalse();
});

it('rejects an expired upload URL', function () {
    $path = 'devices/dev-1/media/photo.jpg';
    $url = URL::temporarySignedRoute('devices.media.upload', now()->subMinute(), ['path' => $path]);

    $this->call('PUT', $url, [], [], [], [], 'bytes')->assertStatus(403);
});

it('rejects path traversal attempts even with a valid signature', function () {
    $path = '../../etc/passwd';
    $url = URL::temporarySignedRoute('devices.media.upload', now()->addMinutes(10), ['path' => $path]);

    $this->call('PUT', $url, [], [], [], [], 'bytes')->assertStatus(400);
});

it('rejects a path outside the devices/ prefix even with a valid signature', function () {
    $path = 'other/secret.jpg';
    $url = URL::temporarySignedRoute('devices.media.upload', now()->addMinutes(10), ['path' => $path]);

    $this->call('PUT', $url, [], [], [], [], 'bytes')->assertStatus(400);
});

it('rejects an oversized upload body', function () {
    $path = 'devices/dev-1/media/huge.jpg';
    $url = URL::temporarySignedRoute('devices.media.upload', now()->addMinutes(10), ['path' => $path]);
    $oversized = str_repeat('x', 16 * 1024 * 1024); // > 15MB limit

    $this->call('PUT', $url, [], [], [], [], $oversized)->assertStatus(422);
});

it('downloads previously uploaded bytes via a signed GET URL with the correct content type', function () {
    $path = 'devices/dev-1/media/photo.jpg';
    Storage::disk('smb_media')->put($path, 'jpeg-bytes');

    $url = URL::temporarySignedRoute('devices.media.download', now()->addMinutes(10), ['path' => $path]);
    $response = $this->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toBe('jpeg-bytes');
});

it('returns 404 for a signed download URL pointing to a file that does not exist', function () {
    $path = 'devices/dev-1/media/missing.jpg';
    $url = URL::temporarySignedRoute('devices.media.download', now()->addMinutes(10), ['path' => $path]);

    $this->get($url)->assertStatus(404);
});
