<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
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

        // §40: SUPER_ADMIN melewati seluruh permission check — termasuk permission baru
        // yang ditambahkan nanti tanpa perlu re-seed role ini (RolePermissionSeeder tetap
        // memberi SUPER_ADMIN semua permission yang ADA saat seed, ini jaring pengaman
        // tambahan untuk permission yang ditambahkan setelahnya).
        Gate::before(function (User $user, string $ability) {
            return $user->hasRole('SUPER_ADMIN') ? true : null;
        });
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
        // Nama 'two-factor-login' dipakai sengaja (bukan 'two-factor') — Fortify sendiri
        // mendaftarkan limiter bawaan bernama 'two-factor' yang memakai session() dan akan
        // bentrok/dipakai tanpa sengaja oleh route API stateless kita jika nama sama (§57).
        RateLimiter::for('two-factor-login', function ($request) {
            return Limit::perMinute(5)->by((string) $request->input('login_token').'|'.$request->ip());
        });

        // OTP unlock (§24): dibatasi per device, dicatat juga attempt_count di tabel device_otps.
        RateLimiter::for('device-otp', function ($request) {
            return Limit::perMinute(5)->by((string) $request->route('device').'|'.$request->ip());
        });

        // §17/§57: percobaan registrasi device dibatasi per-IP — endpoint ini PUBLIK
        // (device belum punya token), jadi satu-satunya pelindung dari brute-force
        // kode registrasi adalah rate limit ini + kode yang short-lived & high-entropy.
        RateLimiter::for('device-registration', function ($request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // §45/§57: heartbeat HTTPS fallback — device yang WS-nya gagal bisa kirim sering,
        // tapi tetap dibatasi supaya tidak jadi vektor DoS per-device.
        RateLimiter::for('device-heartbeat', function ($request) {
            return Limit::perMinute(20)->by((string) $request->input('device_id').'|'.$request->ip());
        });

        // §24/§56/§57: verifikasi OTP dibatasi ketat per device+IP — satu-satunya "gate"
        // endpoint ini adalah OTP itu sendiri, jadi brute force online harus dipersulit
        // di sini SELAIN attempt_count yang tersimpan per-OTP di DB.
        RateLimiter::for('device-otp-verify', function ($request) {
            // CATATAN: $request->route('device') di titik ini MASIH string mentah dari URL,
            // BELUM diresolve jadi model Device — SubstituteBindings middleware jalan
            // SETELAH throttle di pipeline global Laravel. Memanggil ->id di sini meledak
            // (ditemukan lewat test nyata, bukan asumsi). Pakai string-nya langsung saja,
            // cukup sebagai pembeda per-device untuk rate limiting.
            return Limit::perMinute(10)->by((string) $request->route('device').'|'.$request->ip());
        });

        // API umum per user/device yang sudah terautentikasi.
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });
    }
}
