<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * §14/§56: validasi kredensial. Jika 2FA aktif, TIDAK mengeluarkan token di sini —
     * client harus menyelesaikan /auth/two-factor-challenge dulu (step-up, §30/§56).
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        // §58: pesan generik — tidak membedakan "email tidak ada" vs "password salah"
        // supaya tidak bisa dipakai untuk enumerasi akun.
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            activity()
                ->withProperties(['email' => $credentials['email'], 'ip' => $request->ip()])
                ->log('login_failed');

            return ApiResponse::error('Email atau password salah.', [], 401);
        }

        if (! $user->is_active) {
            activity()->causedBy($user)->log('login_blocked_inactive');

            return ApiResponse::error('Akun Anda tidak aktif. Hubungi administrator.', [], 403);
        }

        if ($user->two_factor_confirmed_at !== null) {
            $loginToken = Str::random(64);

            Cache::put(
                "auth:2fa-login:{$loginToken}",
                ['user_id' => $user->id, 'device_name' => $credentials['device_name']],
                now()->addMinutes(5),
            );

            activity()->causedBy($user)->log('login_2fa_required');

            return ApiResponse::success('Verifikasi 2FA diperlukan.', [
                'two_factor_required' => true,
                'login_token' => $loginToken,
                'expires_in' => 300,
            ]);
        }

        return $this->issueToken($user, $credentials['device_name'], $request->ip());
    }

    public static function issueToken(User $user, string $deviceName, ?string $ip): JsonResponse
    {
        $token = $user->createToken($deviceName);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        activity()->causedBy($user)->withProperties(['device_name' => $deviceName, 'ip' => $ip])->log('login_success');

        return ApiResponse::success('Login berhasil.', [
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
            ],
        ], 200);
    }
}
