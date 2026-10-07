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

it('returns an honest 503 when DigitalOcean Spaces is not configured (§66 — no fake success)', function () {
    $device = Device::factory()->create();

    // Sengaja TIDAK fake Storage di sini — mensimulasikan kondisi produksi nyata sebelum
    // DO_SPACES_KEY/SECRET diisi, yang memang kondisi mesin dev ini saat ini.
    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'FRONT']);

    $response->assertStatus(503);
    $this->assertDatabaseCount('device_commands', 0); // command TIDAK dibuat kalau upload URL gagal
});

it('creates a CAMERA_REQUEST command with a presigned upload URL when Spaces is available', function () {
    Storage::fake('spaces');
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'BACK']);

    $response->assertStatus(201)->assertJsonPath('data.command_type', 'CAMERA_REQUEST');
    expect($response->json('data.payload.camera_facing'))->toBe('BACK');
    expect($response->json('data.payload.upload_url'))->not->toBeEmpty();
});

it('rejects an invalid camera_facing value', function () {
    Storage::fake('spaces');
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.cameraToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/camera/request", ['camera_facing' => 'SIDEWAYS'])
        ->assertStatus(422);
});

it('rejects VIEWER from requesting camera capture', function () {
    Storage::fake('spaces');
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

it('returns 404 for a signed URL when the underlying file does not exist in storage', function () {
    Storage::fake('spaces');
    $device = Device::factory()->create();
    $media = DeviceMedia::create([
        'device_id' => $device->id, 'camera_facing' => 'FRONT', 'storage_path' => 'missing.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256_hash' => str_repeat('c', 64),
        'captured_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer '.cameraToken('ADMIN'))
        ->getJson("/api/v1/devices/{$device->id}/media/{$media->id}/url")
        ->assertStatus(404);
});

it('returns a signed URL when the file exists in storage (verified against fake disk)', function () {
    // Storage::fake('spaces') ternyata TETAP mendukung temporaryUrl() (Laravel generate
    // URL lokal yang bisa diserve balik oleh app) — jalur sukses ini jadi bisa diverifikasi
    // tanpa credential Spaces asli. Perilaku dengan S3 asli: method sama, hasilnya URL
    // presigned beneran (bukan lagi perlu diverifikasi terpisah, kodenya identik).
    Storage::fake('spaces');
    Storage::disk('spaces')->put('exists.jpg', 'fake-jpeg-bytes');
    $device = Device::factory()->create();
    $media = DeviceMedia::create([
        'device_id' => $device->id, 'camera_facing' => 'FRONT', 'storage_path' => 'exists.jpg',
        'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'sha256_hash' => str_repeat('d', 64),
        'captured_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer '.cameraToken('ADMIN'))
        ->getJson("/api/v1/devices/{$device->id}/media/{$media->id}/url")
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'expires_in_minutes']]);
});
