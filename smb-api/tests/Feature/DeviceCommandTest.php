<?php

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    // §70: gagal notify gateway tidak boleh menggagalkan request caller — di test ini
    // kita fake HTTP supaya tidak benar2 memanggil AdonisJS (di luar scope test Laravel).
    Http::fake(['*/api/v1/internal/commands/dispatch' => Http::response(['success' => true], 200)]);
});

function operatorToken(): string
{
    $user = User::factory()->create();
    $user->assignRole('OPERATOR');

    return $user->createToken('t')->plainTextToken;
}

it('creates a command and notifies the gateway', function () {
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.operatorToken())
        ->postJson("/api/v1/devices/{$device->id}/commands", ['command_type' => 'LOCATION_REQUEST']);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'PENDING')
        ->assertJsonPath('data.command_type', 'LOCATION_REQUEST');

    $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'command_type' => 'LOCATION_REQUEST']);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/internal/commands/dispatch'));
});

it('rejects an invalid command_type', function () {
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.operatorToken())
        ->postJson("/api/v1/devices/{$device->id}/commands", ['command_type' => 'DO_SOMETHING_EVIL'])
        ->assertStatus(422);
});

it('rejects VIEWER from creating commands (§40)', function () {
    $device = Device::factory()->create();
    $viewer = User::factory()->create();
    $viewer->assignRole('VIEWER');

    $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->postJson("/api/v1/devices/{$device->id}/commands", ['command_type' => 'LOCATION_REQUEST'])
        ->assertStatus(403);
});

it('replays the same command idempotently when idempotency_key repeats (§22)', function () {
    $device = Device::factory()->create();
    $headers = ['Authorization' => 'Bearer '.operatorToken()];

    $first = $this->withHeaders($headers)
        ->postJson("/api/v1/devices/{$device->id}/commands", [
            'command_type' => 'LOCATION_REQUEST',
            'idempotency_key' => 'retry-key-1',
        ])
        ->assertStatus(201);

    $second = $this->withHeaders($headers)
        ->postJson("/api/v1/devices/{$device->id}/commands", [
            'command_type' => 'LOCATION_REQUEST',
            'idempotency_key' => 'retry-key-1',
        ])
        ->assertStatus(200);

    expect($first->json('data.id'))->toBe($second->json('data.id'));
    $this->assertDatabaseCount('device_commands', 1);
});

it('allows two different devices to reuse the same idempotency_key independently', function () {
    $deviceA = Device::factory()->create();
    $deviceB = Device::factory()->create();
    $headers = ['Authorization' => 'Bearer '.operatorToken()];

    $this->withHeaders($headers)
        ->postJson("/api/v1/devices/{$deviceA->id}/commands", ['command_type' => 'LOCATION_REQUEST', 'idempotency_key' => 'shared-key'])
        ->assertStatus(201);

    $this->withHeaders($headers)
        ->postJson("/api/v1/devices/{$deviceB->id}/commands", ['command_type' => 'LOCATION_REQUEST', 'idempotency_key' => 'shared-key'])
        ->assertStatus(201);

    $this->assertDatabaseCount('device_commands', 2);
});

it('expires stale non-terminal commands via the scheduled command (§21)', function () {
    $device = Device::factory()->create();

    $stale = DeviceCommand::create([
        'device_id' => $device->id,
        'command_type' => 'LOCATION_REQUEST',
        'idempotency_key' => 'stale-1',
        'status' => 'SENT',
        'created_by_type' => 'SYSTEM',
        'expires_at' => now()->subMinute(),
    ]);
    $fresh = DeviceCommand::create([
        'device_id' => $device->id,
        'command_type' => 'LOCATION_REQUEST',
        'idempotency_key' => 'fresh-1',
        'status' => 'SENT',
        'created_by_type' => 'SYSTEM',
        'expires_at' => now()->addMinute(),
    ]);
    $alreadyDone = DeviceCommand::create([
        'device_id' => $device->id,
        'command_type' => 'LOCATION_REQUEST',
        'idempotency_key' => 'done-1',
        'status' => 'SUCCESS',
        'created_by_type' => 'SYSTEM',
        'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('smb:expire-stale-commands')->assertSuccessful();

    expect($stale->refresh()->status)->toBe('EXPIRED');
    expect($fresh->refresh()->status)->toBe('SENT');
    expect($alreadyDone->refresh()->status)->toBe('SUCCESS'); // terminal, tidak disentuh
});

it('shows command history for a device, scoped correctly (anti-IDOR)', function () {
    $deviceA = Device::factory()->create();
    $deviceB = Device::factory()->create();
    $headers = ['Authorization' => 'Bearer '.operatorToken()];

    $command = $this->withHeaders($headers)
        ->postJson("/api/v1/devices/{$deviceA->id}/commands", ['command_type' => 'LOCATION_REQUEST'])
        ->json('data');

    // Command milik device A tidak boleh "ketemu" lewat URL device B.
    $this->withHeaders($headers)
        ->getJson("/api/v1/devices/{$deviceB->id}/commands/{$command['id']}")
        ->assertStatus(404);

    $this->withHeaders($headers)
        ->getJson("/api/v1/devices/{$deviceA->id}/commands/{$command['id']}")
        ->assertOk();
});
