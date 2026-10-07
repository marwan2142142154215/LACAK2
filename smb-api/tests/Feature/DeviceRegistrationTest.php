<?php

use App\Models\DeviceRegistrationCode;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    RateLimiter::clear('device-registration');
});

function adminToken(): string
{
    $admin = User::factory()->create();
    $admin->assignRole('ADMIN');

    return $admin->createToken('pest-test')->plainTextToken;
}

it('lets ADMIN generate a registration code tied to a site and team', function () {
    $team = Team::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.adminToken())
        ->postJson('/api/v1/devices/registration-codes', [
            'site_id' => $team->site_id,
            'team_id' => $team->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.site_id', $team->site_id)
        ->assertJsonPath('data.team_id', $team->id);

    expect($response->json('data.code'))->toHaveLength(20);

    $this->assertDatabaseCount('device_registration_codes', 1);
    // code_hash TIDAK PERNAH sama dengan plaintext code yang dikembalikan (Â§39).
    $stored = DeviceRegistrationCode::first();
    expect($stored->toArray())->not->toHaveKey('code_hash');
});

it('rejects a team that does not belong to the given site', function () {
    $team = Team::factory()->create();
    $otherSite = Site::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.adminToken())
        ->postJson('/api/v1/devices/registration-codes', [
            'site_id' => $otherSite->id,
            'team_id' => $team->id,
        ])
        ->assertStatus(422);
});

it('rejects VIEWER from generating registration codes', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('VIEWER');
    $team = Team::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->postJson('/api/v1/devices/registration-codes', [
            'site_id' => $team->site_id,
            'team_id' => $team->id,
        ])
        ->assertStatus(403);
});

it('registers a device with a valid code and returns the secret exactly once', function () {
    $team = Team::factory()->create();
    $token = adminToken();

    $codeResponse = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/devices/registration-codes', ['site_id' => $team->site_id, 'team_id' => $team->id]);
    $code = $codeResponse->json('data.code');

    $response = $this->postJson('/api/v1/devices/register', [
        'code' => $code,
        'android_api_level' => 33,
        'android_version' => '13',
        'app_version' => '1.0.0',
        'manufacturer' => 'Samsung',
        'model' => 'SM-A145F',
        'capability_report' => ['camera_available' => true, 'managed_device' => false],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['device_id', 'public_token_id', 'device_secret']]);

    $this->assertDatabaseCount('devices', 1);
    $this->assertDatabaseCount('device_credentials', 1);
    $this->assertDatabaseHas('device_registration_codes', [
        'id' => DeviceRegistrationCode::first()->id,
        'used_by_device_id' => $response->json('data.device_id'),
    ]);
});

it('rejects reusing the same registration code a second time (Â§17 single-use)', function () {
    $team = Team::factory()->create();
    $token = adminToken();

    $code = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/devices/registration-codes', ['site_id' => $team->site_id, 'team_id' => $team->id])
        ->json('data.code');

    $this->postJson('/api/v1/devices/register', ['code' => $code])->assertStatus(201);

    $second = $this->postJson('/api/v1/devices/register', ['code' => $code]);
    $second->assertStatus(422)->assertJsonPath('message', 'Registration code sudah pernah dipakai.');

    $this->assertDatabaseCount('devices', 1);
});

it('rejects an expired registration code', function () {
    $team = Team::factory()->create();

    $registrationCode = new DeviceRegistrationCode([
        'site_id' => $team->site_id,
        'team_id' => $team->id,
        'created_by' => User::factory()->create()->id,
        'expires_at' => now()->subMinute(),
    ]);
    $registrationCode->forceFill(['code_hash' => hash('sha256', 'EXPIREDCODE123456789')]);
    $registrationCode->save();

    $this->postJson('/api/v1/devices/register', ['code' => 'EXPIREDCODE123456789'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Registration code sudah kedaluwarsa.');
});

it('rejects a revoked registration code', function () {
    $team = Team::factory()->create();

    $registrationCode = new DeviceRegistrationCode([
        'site_id' => $team->site_id,
        'team_id' => $team->id,
        'created_by' => User::factory()->create()->id,
        'expires_at' => now()->addMinutes(15),
    ]);
    $registrationCode->forceFill([
        'code_hash' => hash('sha256', 'REVOKEDCODE123456789'),
        'revoked_at' => now(),
    ]);
    $registrationCode->save();

    $this->postJson('/api/v1/devices/register', ['code' => 'REVOKEDCODE123456789'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Registration code sudah dicabut.');
});

it('rejects an unknown registration code with 404', function () {
    $this->postJson('/api/v1/devices/register', ['code' => 'UNKNOWNCODE123456789'])
        ->assertStatus(404);
});

it('throttles repeated registration attempts per IP (Â§57)', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/devices/register', ['code' => 'XXXXXXXXXXXXXXXXXXXX'])
            ->assertStatus(404);
    }

    $this->postJson('/api/v1/devices/register', ['code' => 'XXXXXXXXXXXXXXXXXXXX'])
        ->assertStatus(429);
});
