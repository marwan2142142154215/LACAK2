<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

function authHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('pest-test')->plainTextToken];
}

/**
 * Hitung TOTP langsung dari counter (bukan wall-clock time()) supaya test deterministik
 * dan tidak terjebak replay-protection Fortify saat dua kode dibutuhkan berurutan cepat.
 */
function totpForCounter(string $secret, int $counterOffset = 0): string
{
    $engine = app(Google2FA::class);

    return $engine->oathTotp($secret, $engine->getTimestamp() + $counterOffset);
}

it('starts 2FA setup and returns a QR code and manual key', function () {
    $user = User::factory()->create();

    $response = $this->withHeaders(authHeader($user))
        ->postJson('/api/v1/auth/two-factor');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['qr_code_svg', 'manual_setup_key']]);

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull(); // belum dikonfirmasi
});

it('does not enable 2FA until the TOTP code is confirmed', function () {
    $user = User::factory()->create();
    $headers = authHeader($user);

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor');
    $user->refresh();

    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $validCode = totpForCounter($secret);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => 'bukan-kode-valid'])
        ->assertStatus(422);

    $confirmResponse = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $validCode]);

    $confirmResponse->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']]);

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

it('requires current_password (step-up) to disable 2FA', function () {
    $user = User::factory()->create(['password' => 'Password123!']);
    $headers = authHeader($user);

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor');
    $user->refresh();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $validCode = totpForCounter($secret);
    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/confirm', ['code' => $validCode]);

    // Password salah -> ditolak, 2FA tetap aktif.
    $this->withHeaders($headers)
        ->deleteJson('/api/v1/auth/two-factor', ['current_password' => 'password-salah'])
        ->assertStatus(422);

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();

    // Password benar -> berhasil dimatikan.
    $this->withHeaders($headers)
        ->deleteJson('/api/v1/auth/two-factor', ['current_password' => 'Password123!'])
        ->assertOk();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
});

it('gates login behind the confirmed 2FA secret end-to-end', function () {
    $user = User::factory()->create(['password' => 'Password123!']);
    $headers = authHeader($user);

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor');
    $user->refresh();
    $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
    $validCode = totpForCounter($secret);
    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/confirm', ['code' => $validCode]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'Password123!',
        'device_name' => 'pest-test',
    ])->assertOk()->assertJsonPath('data.two_factor_required', true);

    $loginToken = $login->json('data.login_token');

    // Counter berbeda (+1 step = +30 detik) supaya kode benar2 berbeda dari $validCode —
    // Fortify menolak kode TOTP yang sama dipakai dua kali (anti-replay).
    $freshCode = totpForCounter($secret, 1);

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'login_token' => $loginToken,
        'code' => $freshCode,
        'device_name' => 'pest-test',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});
