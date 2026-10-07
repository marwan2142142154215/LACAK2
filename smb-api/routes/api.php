<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorSetupController;
use App\Http\Controllers\Api\V1\DeviceHeartbeatController;
use App\Http\Controllers\Api\V1\DeviceRegistrationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\RegistrationCodeController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

// §36: seluruh API berada di bawah /api/v1
Route::prefix('v1')->group(function () {

    // §52: health check (tidak butuh auth, dipakai monitoring & SMB Doctor)
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function () {
        Route::post('/login', LoginController::class)->middleware('throttle:login');
        Route::post('/two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:two-factor-login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', LogoutController::class);
            Route::get('/me', MeController::class);
            Route::get('/sessions', [SessionController::class, 'index']);
            Route::delete('/sessions/{tokenId}', [SessionController::class, 'destroy']);

            // PHASE 5 — 2FA setup (§5/§14/§30)
            Route::get('/two-factor', [TwoFactorSetupController::class, 'show']);
            Route::post('/two-factor', [TwoFactorSetupController::class, 'enable']);
            Route::post('/two-factor/confirm', [TwoFactorSetupController::class, 'confirm']);
            Route::get('/two-factor/recovery-codes', [TwoFactorSetupController::class, 'recoveryCodes']);
            Route::post('/two-factor/recovery-codes', [TwoFactorSetupController::class, 'regenerateRecoveryCodes']);
            Route::delete('/two-factor', [TwoFactorSetupController::class, 'disable']);
        });
    });

    // PHASE 9 — Device registration (§17/§18). PUBLIK & rate-limited — device belum
    // punya token Sanctum sampai registrasi sukses. Letaknya di luar group auth:sanctum
    // dengan sengaja.
    Route::post('/devices/register', [DeviceRegistrationController::class, 'store'])
        ->middleware('throttle:device-registration');

    // PHASE 11 — HTTPS heartbeat fallback (§45). Device-authenticated (bukan Sanctum),
    // lihat AuthenticateDeviceCredential.
    Route::post('/devices/heartbeat', [DeviceHeartbeatController::class, 'store'])
        ->middleware(['device.auth', 'throttle:device-heartbeat']);

    // PHASE 6 — RBAC: Sites & Teams, digerbangi permission (§40), bukan hanya sembunyikan menu
    // di FE — authorize() di controller/FormRequest menegakkannya di server (§14).
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::apiResource('sites', SiteController::class);
        Route::apiResource('teams', TeamController::class);

        // PHASE 9 — admin generate registration code (§17/§31 Download Center)
        Route::get('/devices/registration-codes', [RegistrationCodeController::class, 'index']);
        Route::post('/devices/registration-codes', [RegistrationCodeController::class, 'store']);

        // Rute device-management lainnya (lock/unlock/location/camera/commands) ditambahkan
        // progresif di PHASE 12+ (command broker) — lihat README.md.
    });
});
