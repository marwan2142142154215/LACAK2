<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorSetupController;
use App\Http\Controllers\Api\V1\HealthController;
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

    // PHASE 6 — RBAC: Sites & Teams, digerbangi permission (§40), bukan hanya sembunyikan menu
    // di FE — authorize() di controller/FormRequest menegakkannya di server (§14).
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::apiResource('sites', SiteController::class);
        Route::apiResource('teams', TeamController::class);

        // Rute device-management lainnya (devices/commands/dst) ditambahkan progresif
        // di PHASE 9+ (device registration & command broker) — lihat README.md.
    });
});
