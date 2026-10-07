<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

class TwoFactorChallengeController extends Controller
{
    /**
     * POST /api/v1/auth/two-factor-challenge
     *
     * Langkah kedua login setelah /auth/login mengembalikan login_token (§30 step-up auth).
     * Rate-limited via throttle:two-factor di routes/api.php (anti brute force §56/§57).
     */
    public function __invoke(TwoFactorChallengeRequest $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $cacheKey = "auth:2fa-login:{$request->login_token}";
        $payload = Cache::get($cacheKey);

        if (! $payload) {
            return ApiResponse::error('Sesi verifikasi 2FA sudah habis atau tidak valid. Silakan login ulang.', [], 401);
        }

        /** @var User|null $user */
        $user = User::find($payload['user_id']);

        if (! $user) {
            Cache::forget($cacheKey);

            return ApiResponse::error('Akun tidak ditemukan.', [], 401);
        }

        $verified = false;

        if ($request->filled('code')) {
            $verified = $provider->verify(decrypt($user->two_factor_secret), (string) $request->code);
        } elseif ($request->filled('recovery_code')) {
            $verified = $this->attemptRecoveryCode($user, (string) $request->recovery_code);
        }

        if (! $verified) {
            activity()->causedBy($user)->log('login_2fa_failed');

            return ApiResponse::error('Kode verifikasi tidak valid.', [], 401);
        }

        // Single-use: login_token tidak bisa dipakai ulang (§24 prinsip yang sama dengan OTP).
        Cache::forget($cacheKey);

        activity()->causedBy($user)->log('login_2fa_success');

        return LoginController::issueToken($user, $payload['device_name'], $request->ip());
    }

    /**
     * @see TwoFactorAuthenticatable::replaceRecoveryCode()
     */
    protected function attemptRecoveryCode(User $user, string $code): bool
    {
        $recoveryCodes = $user->recoveryCodes();

        foreach ($recoveryCodes as $recoveryCode) {
            if (hash_equals($recoveryCode, $code)) {
                $user->replaceRecoveryCode($recoveryCode);

                return true;
            }
        }

        return false;
    }
}
