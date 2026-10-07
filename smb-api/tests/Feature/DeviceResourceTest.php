<?php

use App\Models\Device;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function deviceResourceToken(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('lists devices with filters and pagination', function () {
    Device::factory()->create(['status' => 'ONLINE', 'name' => 'Device A']);
    Device::factory()->create(['status' => 'OFFLINE', 'name' => 'Device B']);

    $response = $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('VIEWER'))
        ->getJson('/api/v1/devices?status=ONLINE');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Device A');
});

it('returns overview counts grouped by status', function () {
    Device::factory()->create(['status' => 'ONLINE']);
    Device::factory()->create(['status' => 'ONLINE']);
    Device::factory()->create(['status' => 'OFFLINE']);
    Device::factory()->create(['status' => 'LOCKED']);

    $response = $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('VIEWER'))
        ->getJson('/api/v1/devices/overview');

    $response->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.online', 2)
        ->assertJsonPath('data.offline', 1)
        ->assertJsonPath('data.locked', 1)
        ->assertJsonPath('data.unknown', 0);
});

it('shows a single device with site/team loaded', function () {
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('VIEWER'))
        ->getJson("/api/v1/devices/{$device->id}");

    $response->assertOk()->assertJsonPath('data.id', $device->id);
});

it('lets ADMIN rename a device', function () {
    $device = Device::factory()->create(['name' => 'Old Name']);

    $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('ADMIN'))
        ->patchJson("/api/v1/devices/{$device->id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');
});

it('rejects VIEWER renaming a device', function () {
    $device = Device::factory()->create(['name' => 'Old Name']);

    $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('VIEWER'))
        ->patchJson("/api/v1/devices/{$device->id}", ['name' => 'Hacked'])
        ->assertStatus(403);
});

it('lets ADMIN soft-delete a device', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('ADMIN'))
        ->deleteJson("/api/v1/devices/{$device->id}")
        ->assertOk();

    expect(Device::withTrashed()->find($device->id)->trashed())->toBeTrue();
});

it('rejects OPERATOR soft-deleting a device', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.deviceResourceToken('OPERATOR'))
        ->deleteJson("/api/v1/devices/{$device->id}")
        ->assertStatus(403);
});
