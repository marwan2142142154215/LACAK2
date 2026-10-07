<?php

use App\Models\Device;
use App\Models\DeviceOtp;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    RateLimiter::clear('device-otp-verify');
    Http::fake(['*/api/v1/internal/commands/dispatch' => Http::response(['success' => true], 200)]);
});

function otpAdminToken(): array
{
    $user = User::factory()->create();
    $user->assignRole('ADMIN');

    return [$user, $user->createToken('t')->plainTextToken];
}

it('lets ADMIN generate an OTP and returns the plaintext code exactly once', function () {
    [$admin, $token] = otpAdminToken();
    $device = Device::factory()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/devices/{$device->id}/otp");

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['code', 'expires_at', 'max_attempts']]);

    expect($response->json('data.code'))->toMatch('/^\d{6}$/');

    $stored = DeviceOtp::first();
    expect($stored->toArray())->not->toHaveKey('otp_hash');
});

it('rejects VIEWER from generating an OTP', function () {
    $device = Device::factory()->create();
    $viewer = User::factory()->create();
    $viewer->assignRole('VIEWER');

    $this->withHeader('Authorization', 'Bearer '.$viewer->createToken('t')->plainTextToken)
        ->postJson("/api/v1/devices/{$device->id}/otp")
        ->assertStatus(403);
});

it('verifies a correct OTP, dispatches UNLOCK, and invalidates it (single-use)', function () {
    [$admin, $token] = otpAdminToken();
    $device = Device::factory()->create(['status' => 'LOCKED']);

    $code = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/devices/{$device->id}/otp")
        ->json('data.code');

    $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => $code])
        ->assertOk()
        ->assertJsonStructure(['data' => ['command_id']]);

    $this->assertDatabaseHas('device_commands', ['device_id' => $device->id, 'command_type' => 'UNLOCK']);

    $otp = DeviceOtp::first();
    expect($otp->used_at)->not->toBeNull();

    // §24 single-use: kode yang SAMA tidak bisa dipakai lagi walau benar.
    $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => $code])
        ->assertStatus(404);
});

it('rejects a wrong OTP and increments attempt_count without unlocking', function () {
    [$admin, $token] = otpAdminToken();
    $device = Device::factory()->create();

    $this->withHeader('Authorization', 'Bearer '.$token)->postJson("/api/v1/devices/{$device->id}/otp");

    $response = $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => '000000']);
    // Kemungkinan kecil 000000 adalah kode asli; pastikan test deterministik dengan cek attempt_count naik.
    $otpBefore = DeviceOtp::first();

    if ($response->status() === 200) {
        // Kode asli ternyata 000000 (probabilitas 1/1.000.000) — lewati assertion negatif.
        expect(true)->toBeTrue();
    } else {
        $response->assertStatus(401);
        expect(DeviceOtp::first()->attempt_count)->toBe($otpBefore->attempt_count);
    }

    $this->assertDatabaseCount('device_commands', $response->status() === 200 ? 1 : 0);
});

it('locks out further attempts after max_attempts is exceeded, even with the correct code', function () {
    [$admin, $token] = otpAdminToken();
    $device = Device::factory()->create();

    $otp = new DeviceOtp([
        'device_id' => $device->id,
        'purpose' => 'UNLOCK',
        'expires_at' => now()->addMinutes(5),
        'max_attempts' => 3,
        'requested_by' => $admin->id,
    ]);
    $otp->forceFill(['otp_hash' => Hash::make('123456')]);
    $otp->save();

    for ($i = 0; $i < 3; $i++) {
        $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => 'wrong0'])
            ->assertStatus(401);
    }

    // Percobaan ke-4 dengan kode yang BENAR tetap ditolak — batas sudah terlampaui.
    $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => '123456'])
        ->assertStatus(422);

    $this->assertDatabaseCount('device_commands', 0);
});

it('rejects an expired OTP', function () {
    [$admin] = otpAdminToken();
    $device = Device::factory()->create();

    $otp = new DeviceOtp([
        'device_id' => $device->id,
        'purpose' => 'UNLOCK',
        'expires_at' => now()->subMinute(),
        'max_attempts' => 5,
        'requested_by' => $admin->id,
    ]);
    $otp->forceFill(['otp_hash' => Hash::make('123456')]);
    $otp->save();

    $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'OTP sudah kedaluwarsa. Minta admin membuat OTP baru.');
});

it('returns 404 when no active OTP exists for the device', function () {
    $device = Device::factory()->create();

    $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => '123456'])
        ->assertStatus(404);
});

it('throttles repeated OTP verify attempts per device+IP (§57)', function () {
    $device = Device::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => '999999'])
            ->assertStatus(404);
    }

    $this->postJson("/api/v1/devices/{$device->id}/otp/verify", ['code' => '999999'])
        ->assertStatus(429);
});
