<?php

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceNetworkViolation;
use App\Models\SiteNetworkPolicy;
use App\Models\Team;
use App\Models\TelegramAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('device-heartbeat');
    $this->seed(RolePermissionSeeder::class);
});

function seedPolicedDevice(string $allowedIp = '203.0.113.10'): array
{
    $team = Team::factory()->create();
    $device = Device::create([
        'site_id' => $team->site_id,
        'team_id' => $team->id,
        'name' => 'Policed Device',
        'status' => 'UNKNOWN',
        'is_active' => true,
    ]);
    SiteNetworkPolicy::create([
        'site_id' => $device->site_id,
        'network_type' => 'IP',
        'value' => $allowedIp,
        'is_active' => true,
    ]);
    $publicTokenId = (string) Str::uuid();
    DeviceCredential::create([
        'device_id' => $device->id,
        'credential_hash' => Hash::make('correct-secret'),
        'public_token_id' => $publicTokenId,
        'issued_at' => now(),
    ]);

    return [$device, $publicTokenId];
}

function sendHeartbeatFromIp(Device $device, string $publicTokenId, string $ip)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/devices/heartbeat', [
        'device_id' => $device->id,
        'public_token_id' => $publicTokenId,
        'device_secret' => 'correct-secret',
        'battery_level' => 80,
        'network_type' => 'WIFI',
        'connection_state' => 'CONNECTED',
    ]);
}

it('creates a violation row and sends ONE Telegram alert the first time a device heartbeats from a disallowed IP', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramAccount::create(['telegram_id' => 111, 'telegram_username' => 'admin1', 'user_id' => $user->id, 'status' => 'APPROVED']);

    [$device, $publicTokenId] = seedPolicedDevice('203.0.113.10');

    $response = sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');

    $response->assertOk()->assertJsonPath('data.network_status', 'BLOCKED');
    $this->assertDatabaseCount('device_network_violations', 1);
    $violation = DeviceNetworkViolation::first();
    expect($violation->resolved_at)->toBeNull();
    expect($violation->alert_sent_at)->not->toBeNull();

    Http::assertSentCount(1);
});

it('does NOT send a second alert or create a second row for a violation that is still ongoing (§105 anti-spam)', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramAccount::create(['telegram_id' => 111, 'telegram_username' => 'admin1', 'user_id' => $user->id, 'status' => 'APPROVED']);

    [$device, $publicTokenId] = seedPolicedDevice('203.0.113.10');

    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');
    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');
    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');

    $this->assertDatabaseCount('device_network_violations', 1);
    Http::assertSentCount(1);
});

it('resolves the open violation once the device heartbeats from an allowed IP again', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    config(['services.telegram.bot_token' => 'test-token']);

    [$device, $publicTokenId] = seedPolicedDevice('203.0.113.10');

    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');
    $open = DeviceNetworkViolation::first();
    expect($open->resolved_at)->toBeNull();

    sendHeartbeatFromIp($device, $publicTokenId, '203.0.113.10');

    $open->refresh();
    expect($open->resolved_at)->not->toBeNull();
});

it('opens a NEW violation (and sends a new alert) if the device violates again after being resolved (§105)', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    config(['services.telegram.bot_token' => 'test-token']);
    $user = User::factory()->create();
    TelegramAccount::create(['telegram_id' => 111, 'telegram_username' => 'admin1', 'user_id' => $user->id, 'status' => 'APPROVED']);

    [$device, $publicTokenId] = seedPolicedDevice('203.0.113.10');

    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99'); // violation #1 opens
    sendHeartbeatFromIp($device, $publicTokenId, '203.0.113.10'); // resolved
    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.50'); // violation #2 opens

    $this->assertDatabaseCount('device_network_violations', 2);
    Http::assertSentCount(2);
});

it('lets ADMIN list open network violations via the monitoring endpoint', function () {
    [$device, $publicTokenId] = seedPolicedDevice('203.0.113.10');
    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');

    $admin = User::factory()->create();
    $admin->assignRole('ADMIN');
    $token = $admin->createToken('t')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/network-violations');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('rejects VIEWER-without-permission scenarios gracefully — but allows VIEWER who does have network.view', function () {
    [$device, $publicTokenId] = seedPolicedDevice('203.0.113.10');
    sendHeartbeatFromIp($device, $publicTokenId, '198.51.100.99');

    $viewer = User::factory()->create();
    $viewer->assignRole('VIEWER');
    $token = $viewer->createToken('t')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/network-violations')->assertOk();
});
