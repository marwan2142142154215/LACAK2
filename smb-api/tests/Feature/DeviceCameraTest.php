<?php

use App\Models\Device;
use App\Models\DeviceMedia;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Http::fake(['*/api/v1/internal/commands/dispatch' => Http::response(['success' => true], 200)]);
});

function cameraToken(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('creates a CAMERA_REQUEST command with a signed local upload URL by default (§110-114)', function () {
    // §110: default storage adalah lokal (perangkat/server pemilik produk sendiri) — TIDAK
    // butuh konfigurasi cloud apa pun untuk berhasil. Tidak ada Storage::fake() di sini
    // dengan sengaja, membuktikan jalur sukses tidak diam-diam butuh Spaces.
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'BACK']);

    $response->assertStatus(201)->assertJsonPath('data.command_type', 'CAMERA_REQUEST');
    expect($response->json('data.payload.camera_facing'))->toBe('BACK');
    $uploadUrl = $response->json('data.payload.upload_url');
    expect($uploadUrl)->not->toBeEmpty();
    expect($uploadUrl)->toContain('/api/v1/devices/media/upload');
    expect($uploadUrl)->toContain('signature='); // §79: bukan URL biasa, WAJIB signed.
});

it('returns an honest 503 when Spaces mode is selected but credentials are missing (§66 — no fake success)', function () {
    config(['filesystems.default_media_disk' => 'spaces']);
    $device = Device::factory()->create();

    // Sengaja TIDAK fake Storage di sini — mensimulasikan kondisi produksi nyata sebelum
    // DO_SPACES_KEY/SECRET diisi, kalau operator memilih mode Spaces.
    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'FRONT']);

    $response->assertStatus(503);
    $this->assertDatabaseCount('device_commands', 0); // command TIDAK dibuat kalau upload URL gagal
});

it('creates a CAMERA_REQUEST command with a presigned Spaces upload URL when Spaces mode is configured', function () {
    config([
        'filesystems.default_media_disk' => 'spaces',
        'filesystems.disks.spaces.key' => 'fake-key',
        'filesystems.disks.spaces.secret' => 'fake-secret',
    ]);
    Storage::fake('spaces');
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'BACK']);

    $response->assertStatus(201)->assertJsonPath('data.command_type', 'CAMERA_REQUEST');
    expect($response->json('data.payload.upload_url'))->not->toBeEmpty();
});

it('rejects an invalid camera_facing value', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'SIDEWAYS'])
        ->assertStatus(422);
});

it('rejects VIEWER from requesting camera capture', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.cameraToken('VIEWER'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'FRONT'])
        ->assertStatus(403);
});

it('lists captured media for a device, scoped correctly (anti-IDOR)', function () {
    $deviceA = Device::factory()->create();
    $deviceB = Device::factory()->create();

    DeviceMedia::create([
        'device_id' => $deviceA->id, 'camera_facing' => 'FRONT', 'storage_path' => 'x.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256_hash' => str_repeat('a', 64),
        'captured_at' => now(),
    ]);
    DeviceMedia::create([
        'device_id' => $deviceB->id, 'camera_facing' => 'BACK', 'storage_path' => 'y.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256_hash' => str_repeat('b', 64),
        'captured_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('ADMIN'))
        ->getJson("/api/v1/devices/{$deviceA->id}/media");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('returns 404 for a signed URL when the underlying file does not exist in local storage', function () {
    Storage::fake('smb_media');
    $device = Device::factory()->create();
    $media = DeviceMedia::create([
        'device_id' => $device->id, 'camera_facing' => 'FRONT', 'storage_path' => 'devices/x/media/missing.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256_hash' => str_repeat('c', 64),
        'captured_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer '.cameraToken('ADMIN'))
        ->getJson("/api/v1/devices/{$device->id}/media/{$media->id}/url")
        ->assertStatus(404);
});

it('returns a signed local URL when the file exists in storage', function () {
    Storage::fake('smb_media');
    Storage::disk('smb_media')->put('devices/x/media/exists.jpg', 'fake-jpeg-bytes');
    $device = Device::factory()->create();
    $media = DeviceMedia::create([
        'device_id' => $device->id, 'camera_facing' => 'FRONT', 'storage_path' => 'devices/x/media/exists.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256_hash' => str_repeat('d', 64),
        'captured_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('ADMIN'))
        ->getJson("/api/v1/devices/{$device->id}/media/{$media->id}/url")
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'expires_in_minutes']]);

    expect($response->json('data.url'))->toContain('/api/v1/devices/media/download');
});
