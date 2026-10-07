<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * §57: rate-limit & brute-force protection untuk endpoint sensitif.
     */
    protected function registerRateLimiters(): void
    {
        // Login: per kombinasi email+IP, supaya satu IP tidak bisa menghabiskan percobaan
        // akun lain, tapi tetap dibatasi per akun untuk mencegah credential stuffing.
        RateLimiter::for('login', function ($request) {
            $email = (string) $request->input('email');

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinutes(15, 20)->by($request->ip()),
            ];
        });

        // 2FA challenge: percobaan kode dibatasi lebih ketat (anti brute force §56/§57).
        RateLimiter::for('two-factor', function ($request) {
            return Limit::perMinute(5)->by((string) $request->input('login_token').'|'.$request->ip());
        });

        // OTP unlock (§24): dibatasi per device, dicatat juga attempt_count di tabel device_otps.
        RateLimiter::for('device-otp', function ($request) {
            return Limit::perMinute(5)->by((string) $request->route('device').'|'.$request->ip());
        });

        // API umum per user/device yang sudah terautentikasi.
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
