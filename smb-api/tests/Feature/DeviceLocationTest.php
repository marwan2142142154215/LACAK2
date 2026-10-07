<?php

use App\Models\Device;
use App\Models\DeviceLocation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Http::fake(['*/api/v1/internal/commands/dispatch' => Http::response(['success' => true], 200)]);
});

function locationToken(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('lets OPERATOR request a device location via the command broker', function () {
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.locationToken('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/location/request");

    $response->assertStatus(201)->assertJsonPath('data.command_type', 'LOCATION_REQUEST');
    $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'command_type' => 'LOCATION_REQUEST']);
});

it('rejects VIEWER from requesting location (needs devices.location)', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.locationToken('VIEWER'))
        ->postJson("/api/v1/devices/{$device->id}/location/request")
        ->assertStatus(403);
});

it('lists location history for a device, paginated and scoped (anti-IDOR)', function () {
    $deviceA = Device::factory()->create();
    $deviceB = Device::factory()->create();

    DeviceLocation::create([
        'device_id' => $deviceA->id, 'latitude' => -6.2, 'longitude' => 106.8,
        'source' => 'GPS', 'recorded_at' => now(), 'received_at' => now(),
    ]);
    DeviceLocation::create([
        'device_id' => $deviceB->id, 'latitude' => -7.0, 'longitude' => 110.0,
        'source' => 'NETWORK', 'recorded_at' => now(), 'received_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.locationToken('ADMIN'))
        ->getJson("/api/v1/devices/{$deviceA->id}/locations");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.device_id'))->toBe($deviceA->id);
});

it('returns the most recent location via /locations/latest', function () {
    $device = Device::factory()->create();

    DeviceLocation::create([
        'device_id' => $device->id, 'latitude' => -6.1, 'longitude' => 106.7,
        'source' => 'LAST_KNOWN', 'recorded_at' => now()->subHour(), 'received_at' => now()->subHour(),
    ]);
    $latest = DeviceLocation::create([
        'device_id' => $device->id, 'latitude' => -6.2, 'longitude' => 106.8,
        'source' => 'GPS', 'recorded_at' => now(), 'received_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.locationToken('ADMIN'))
        ->getJson("/api/v1/devices/{$device->id}/locations/latest");

    $response->assertOk()->assertJsonPath('data.id', $latest->id);
});

it('returns 404 from /locations/latest when the device has no location history', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.locationToken('ADMIN'))
        ->getJson("/api/v1/devices/{$device->id}/locations/latest")
        ->assertStatus(404);
});
