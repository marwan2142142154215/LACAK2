<?php

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\Site;
use App\Models\Team;
use App\Services\DeviceStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('device-heartbeat');
});

function seedDeviceWithCredential(string $rawSecret = 'correct-secret', ?string $status = 'UNKNOWN', $lastHeartbeatAt = null): array
{
    $team = Team::factory()->create();
    $device = Device::create([
        'site_id' => $team->site_id,
        'team_id' => $team->id,
        'name' => 'Test Device',
        'status' => $status,
        'is_active' => true,
        'last_heartbeat_at' => $lastHeartbeatAt,
    ]);
    $publicTokenId = (string) Str::uuid();
    DeviceCredential::create([
        'device_id' => $device->id,
        'credential_hash' => Hash::make($rawSecret),
        'public_token_id' => $publicTokenId,
        'issued_at' => now(),
    ]);

    return [$device, $publicTokenId];
}

it('accepts a valid heartbeat and updates device status to ONLINE', function () {
    [$device, $publicTokenId] = seedDeviceWithCredential();

    $response = $this->postJson('/api/v1/devices/heartbeat', [
        'device_id' => $device->id,
        'public_token_id' => $publicTokenId,
        'device_secret' => 'correct-secret',
        'battery_level' => 80,
        'network_type' => 'WIFI',
        'connection_state' => 'CONNECTED',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.status', 'ONLINE');

    $device->refresh();
    expect($device->status)->toBe('ONLINE');
    expect($device->last_heartbeat_at)->not->toBeNull();

    $this->assertDatabaseCount('device_heartbeats', 1);
    $this->assertDatabaseHas('device_heartbeats', ['device_id' => $device->id, 'battery_level' => 80]);
});

it('rejects a heartbeat with wrong device_secret', function () {
    [$device, $publicTokenId] = seedDeviceWithCredential();

    $this->postJson('/api/v1/devices/heartbeat', [
        'device_id' => $device->id,
        'public_token_id' => $publicTokenId,
        'device_secret' => 'WRONG',
    ])->assertStatus(401);

    $this->assertDatabaseCount('device_heartbeats', 0);
});

it('rejects a heartbeat for a revoked credential', function () {
    [$device, $publicTokenId] = seedDeviceWithCredential();
    DeviceCredential::where('device_id', $device->id)->update(['revoked_at' => now()]);

    $this->postJson('/api/v1/devices/heartbeat', [
        'device_id' => $device->id,
        'public_token_id' => $publicTokenId,
        'device_secret' => 'correct-secret',
    ])->assertStatus(401);
});

it('rejects a heartbeat for an inactive device', function () {
    [$device, $publicTokenId] = seedDeviceWithCredential();
    $device->update(['is_active' => false]);

    $this->postJson('/api/v1/devices/heartbeat', [
        'device_id' => $device->id,
        'public_token_id' => $publicTokenId,
        'device_secret' => 'correct-secret',
    ])->assertStatus(401);
});

it('throttles excessive heartbeats from the same device (§57)', function () {
    [$device, $publicTokenId] = seedDeviceWithCredential();
    $payload = ['device_id' => $device->id, 'public_token_id' => $publicTokenId, 'device_secret' => 'correct-secret'];

    for ($i = 0; $i < 20; $i++) {
        $this->postJson('/api/v1/devices/heartbeat', $payload)->assertOk();
    }

    $this->postJson('/api/v1/devices/heartbeat', $payload)->assertStatus(429);
});

it('resolves status from last_heartbeat_at age via DeviceStatusResolver (§6)', function () {
    $resolver = new DeviceStatusResolver();

    expect($resolver->resolve(null))->toBe('UNKNOWN');
    expect($resolver->resolve(now()->subSeconds(10)))->toBe('ONLINE');
    expect($resolver->resolve(now()->subSeconds(200)))->toBe('DEGRADED');
    expect($resolver->resolve(now()->subSeconds(400)))->toBe('OFFLINE');
});

it('recomputes stale device statuses via the scheduled command', function () {
    [$deviceStale] = seedDeviceWithCredential('secret-a', 'ONLINE', now()->subMinutes(10));
    [$deviceFresh] = seedDeviceWithCredential('secret-b', 'ONLINE', now()->subSeconds(5));

    $this->artisan('smb:recompute-device-statuses')->assertSuccessful();

    expect($deviceStale->refresh()->status)->toBe('OFFLINE');
    expect($deviceFresh->refresh()->status)->toBe('ONLINE');
});
