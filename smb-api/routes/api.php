<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorSetupController;
use App\Http\Controllers\Api\V1\DeviceCameraController;
use App\Http\Controllers\Api\V1\DeviceCommandController;
use App\Http\Controllers\Api\V1\DeviceHeartbeatController;
use App\Http\Controllers\Api\V1\DeviceLocationController;
use App\Http\Controllers\Api\V1\DeviceLockController;
use App\Http\Controllers\Api\V1\DeviceOtpController;
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

    // PHASE 14 — OTP unlock self-service (§24). PUBLIK & rate-limited dengan sengaja —
    // satu-satunya gate adalah OTP itu sendiri (hashed, attempt-limited, single-use).
    Route::post('/devices/{device}/otp/verify', [DeviceOtpController::class, 'verify'])
        ->middleware('throttle:device-otp-verify');

    // PHASE 6 — RBAC: Sites & Teams, digerbangi permission (§40), bukan hanya sembunyikan menu
    // di FE — authorize() di controller/FormRequest menegakkannya di server (§14).
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::apiResource('sites', SiteController::class);
        Route::apiResource('teams', TeamController::class);

        // PHASE 9 — admin generate registration code (§17/§31 Download Center)
        Route::get('/devices/registration-codes', [RegistrationCodeController::class, 'index']);
        Route::post('/devices/registration-codes', [RegistrationCodeController::class, 'store']);

        // PHASE 12 — command broker (§19-22). Lock/unlock/location/camera-specific
        // endpoint (§23-27) dibangun di atas ini PHASE 13-16, bukan menggantikannya.
        Route::get('/devices/{device}/commands', [DeviceCommandController::class, 'index']);
        Route::post('/devices/{device}/commands', [DeviceCommandController::class, 'store']);
        Route::get('/devices/{device}/commands/{command}', [DeviceCommandController::class, 'show']);

        // PHASE 13 — Lock/Unlock (§23/§24), wrapper tipis di atas command broker.
        Route::post('/devices/{device}/lock', [DeviceLockController::class, 'lock']);
        Route::post('/devices/{device}/unlock', [DeviceLockController::class, 'unlock']);

        // PHASE 14 — admin generate OTP (§24).
        Route::post('/devices/{device}/otp', [DeviceOtpController::class, 'store']);

        // PHASE 15 — Location (§25/§26).
        Route::post('/devices/{device}/location/request', [DeviceLocationController::class, 'request']);
        Route::get('/devices/{device}/locations', [DeviceLocationController::class, 'index']);
        Route::get('/devices/{device}/locations/latest', [DeviceLocationController::class, 'latest']);

        // PHASE 16 — Camera (§27/§28).
        Route::post('/devices/{device}/camera/request', [DeviceCameraController::class, 'request']);
        Route::get('/devices/{device}/media', [DeviceCameraController::class, 'index']);
        Route::get('/devices/{device}/media/{media}/url', [DeviceCameraController::class, 'url']);
    });
});
