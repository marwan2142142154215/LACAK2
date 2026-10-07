<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CurrentPasswordRequest;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * PHASE 5 — 2FA setup flow. Hanya dicapai untuk USER yang SUDAH login (butuh token Sanctum
 * valid) — enable/disable 2FA BUKAN endpoint publik.
 */
class TwoFactorSetupController extends Controller
{
    /**
     * GET /api/v1/auth/two-factor — status 2FA user saat ini.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success('Status 2FA.', [
            'enabled' => $user->two_factor_secret !== null,
            'confirmed' => $user->two_factor_confirmed_at !== null,
        ]);
    }

    /**
     * POST /api/v1/auth/two-factor — mulai setup 2FA: generate secret + QR, BELUM aktif
     * sampai di-konfirmasi via kode TOTP (mencegah user terkunci kalau salah scan QR).
     */
    public function enable(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return ApiResponse::error('2FA sudah aktif. Disable dulu sebelum setup ulang.', [], 422);
        }

        $enable($user, force: true);
        $user->refresh();

        activity()->causedBy($user)->log('two_factor_setup_started');

        return ApiResponse::success('Scan QR code lalu konfirmasi dengan kode dari aplikasi otentikator.', [
            'qr_code_svg' => $user->twoFactorQrCodeSvg(),
            'manual_setup_key' => \Laravel\Fortify\Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        ]);
    }

    /**
     * POST /api/v1/auth/two-factor/confirm — konfirmasi kode TOTP pertama, mengaktifkan 2FA
     * secara penuh dan mengembalikan recovery codes (hanya ditampilkan SEKALI di sini).
     */
    public function confirm(TwoFactorConfirmRequest $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $user = $request->user();

        // ConfirmTwoFactorAuthentication melempar ValidationException sendiri jika kode salah
        // — otomatis tertangkap exception handler global (§36/§37), jadi tidak perlu try/catch.
        $confirm($user, $request->code);
        $user->refresh();

        activity()->causedBy($user)->log('two_factor_confirmed');

        return ApiResponse::success('2FA berhasil diaktifkan.', [
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    /**
     * GET /api/v1/auth/two-factor/recovery-codes — lihat ulang recovery codes yang masih tersisa.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at === null) {
            return ApiResponse::error('2FA belum aktif.', [], 422);
        }

        return ApiResponse::success('Recovery codes.', ['recovery_codes' => $user->recoveryCodes()]);
    }

    /**
     * POST /api/v1/auth/two-factor/recovery-codes — regenerasi seluruh recovery codes
     * (step-up: wajib current_password, §30).
     */
    public function regenerateRecoveryCodes(
        CurrentPasswordRequest $request,
        GenerateNewRecoveryCodes $generate,
    ): JsonResponse {
        $user = $request->user();

        if ($user->two_factor_confirmed_at === null) {
            return ApiResponse::error('2FA belum aktif.', [], 422);
        }

        $generate($user);
        $user->refresh();

        activity()->causedBy($user)->log('two_factor_recovery_codes_regenerated');

        return ApiResponse::success('Recovery codes baru dibuat. Kode lama tidak berlaku lagi.', [
            'recovery_codes' => $user->recoveryCodes(),
        ]);
    }

    /**
     * DELETE /api/v1/auth/two-factor — matikan 2FA (step-up: wajib current_password, §30).
     */
    public function disable(CurrentPasswordRequest $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $user = $request->user();

        $disable($user);

        activity()->causedBy($user)->log('two_factor_disabled');

        return ApiResponse::success('2FA berhasil dimatikan.');
    }
}
