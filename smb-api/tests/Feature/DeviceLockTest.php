<?php

use App\Models\Device;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Http::fake(['*/api/v1/internal/commands/dispatch' => Http::response(['success' => true], 200)]);
});

function tokenWithRole(string $role): string
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->createToken('t')->plainTextToken;
}

it('lets OPERATOR lock a device and creates a LOCK command with the expected message', function () {
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.tokenWithRole('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/lock");

    $response->assertStatus(201)
        ->assertJsonPath('data.command_type', 'LOCK')
        ->assertJsonPath('data.payload.message', 'Segera kembali ke tempat asal anda');

    $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'command_type' => 'LOCK']);
});

it('rejects locking a device that is already LOCKED', function () {
    $device = Device::factory()->create(['status' => 'LOCKED']);

    $this->withHeader('Authorization', 'Bearer '.tokenWithRole('OPERATOR'))
        ->postJson("/api/v1/devices/{$device->id}/lock")
        ->assertStatus(422);
});

it('rejects VIEWER from locking or unlocking a device', function () {
    $device = Device::factory()->create();
    $token = tokenWithRole('VIEWER');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/devices/{$device->id}/lock")
        ->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/devices/{$device->id}/unlock")
        ->assertStatus(403);
});

it('lets ADMIN unlock a device directly via dashboard channel (§24 — valid without OTP)', function () {
    $device = Device::factory()->create(['status' => 'LOCKED']);

    $this->withHeader('Authorization', 'Bearer '.tokenWithRole('ADMIN'))
        ->postJson("/api/v1/devices/{$device->id}/unlock")
        ->assertStatus(201)
        ->assertJsonPath('data.command_type', 'UNLOCK');

    $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'command_type' => 'UNLOCK']);
});
