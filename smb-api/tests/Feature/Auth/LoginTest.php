<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('login');
});

it('logs in with valid credentials and issues a token', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password123!',
        'device_name' => 'pest-test',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonStructure(['data' => ['token']]);
});

it('rejects invalid password with a generic message (anti enumeration, §58)', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'pest-test',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('success', false);
});

it('rejects login for unknown email with the exact same generic message', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@nowhere.local',
        'password' => 'whatever',
        'device_name' => 'pest-test',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Email atau password salah.');
});

it('blocks login for inactive user', function () {
    $user = User::factory()->create(['password' => 'Password123!', 'is_active' => false]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password123!',
        'device_name' => 'pest-test',
    ]);

    $response->assertStatus(403);
});

it('requires two-factor challenge when 2FA is enabled and does not issue a token directly', function () {
    $user = User::factory()->create([
        'password' => 'Password123!',
        'two_factor_secret' => encrypt('TESTSECRETBASE32'),
        'two_factor_confirmed_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password123!',
        'device_name' => 'pest-test',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['login_token']]);

    expect($response->json('data.token'))->toBeNull();
});

it('throttles repeated failed login attempts (§57 brute force protection)', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
            'device_name' => 'pest-test',
        ])->assertStatus(401);
    }

    // Percobaan ke-6 dalam 1 menit harus ditolak oleh rate limiter, bukan diproses lagi.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong',
        'device_name' => 'pest-test',
    ])->assertStatus(429);
});

it('allows an authenticated user to fetch /auth/me', function () {
    $user = User::factory()->create();
    $token = $user->createToken('pest-test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});

it('rejects requests without a token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('logs out and revokes the current token so it cannot be reused', function () {
    $user = User::factory()->create();
    $token = $user->createToken('pest-test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk();

    // RequestGuard (Sanctum) men-cache user yang sudah ter-resolve per instance AuthManager,
    // yang tetap hidup antar sub-request DALAM satu test method (beda dari production: satu
    // request = satu lifecycle baru). forgetGuards() mensimulasikan request HTTP yang benar-benar
    // baru supaya assertion ini mencerminkan perilaku produksi yang sebenarnya, bukan artefak test.
    $this->app->make('auth')->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/auth/me')
        ->assertStatus(401);
});

it('only lists and revokes sessions belonging to the authenticated user (anti-IDOR, §58)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $tokenA = $userA->createToken('a-device');
    $tokenB = $userB->createToken('b-device');

    $plainA = $tokenA->plainTextToken;

    // User A tidak boleh bisa menghapus token milik user B dengan menebak ID.
    $this->withHeader('Authorization', "Bearer {$plainA}")
        ->deleteJson("/api/v1/auth/sessions/{$tokenB->accessToken->id}")
        ->assertStatus(404);

    expect($userB->tokens()->count())->toBe(1);
});
