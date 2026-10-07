<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

// §36: seluruh API berada di bawah /api/v1
Route::prefix('v1')->group(function () {

    // §52: health check (tidak butuh auth, dipakai monitoring & SMB Doctor)
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function () {
        Route::post('/login', LoginController::class)->middleware('throttle:login');
        Route::post('/two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:two-factor');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('/logout', LogoutController::class);
            Route::get('/me', MeController::class);
            Route::get('/sessions', [SessionController::class, 'index']);
            Route::delete('/sessions/{tokenId}', [SessionController::class, 'destroy']);
        });
    });

    // Rute device-management (sites/teams/devices/commands/dst) ditambahkan progresif
    // di PHASE 6 (RBAC) dan PHASE 9+ (device registration & command broker) — lihat README.md.
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        //
    });
});
