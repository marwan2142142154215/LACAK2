<?php

use App\Http\Middleware\AuthenticateDeviceCredential;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CATATAN: SENGAJA TIDAK memanggil $middleware->statefulApi(). Arsitektur kita adalah
        // bearer-token SPA (dashboard Vue & kedua app Android mengirim header
        // "Authorization: Bearer <token>", TIDAK PERNAH cookie session) — lihat semua 78 test
        // backend + docs/api.md. statefulApi() membuat Sanctum menganggap request dari domain
        // "stateful" (termasuk "localhost" di port manapun) sebagai first-party SPA cookie-based
        // dan mewajibkan CSRF token yang memang tidak pernah kita kirim — ditemukan lewat test
        // nyata di browser (login dari localhost:5173 ditolak "CSRF token mismatch"), bukan lewat
        // Pest (Pest tidak mengirim header Origin/Referer jadi tidak kena jalur ini sama sekali).

        // §100/§131: aplikasi berjalan DI BELAKANG Cloudflare Tunnel (cloudflared lokal,
        // PHASE 20) — satu-satunya sumber koneksi inbound yang mungkin adalah proses
        // cloudflared di loopback. Percaya loopback sebagai proxy supaya header
        // CF-Connecting-IP/X-Forwarded-For dari Cloudflare bisa dipakai NetworkPolicyEvaluator
        // untuk tahu IP client ASLI — TANPA ini, $request->ip() akan selalu "127.0.0.1" untuk
        // SEMUA device (tidak berguna untuk IP whitelist §97-102).
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // Spatie Laravel Permission (§40) — alias middleware tidak lagi auto-register di Laravel 11+.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // §43: autentikasi device (bukan Sanctum user) untuk endpoint HTTPS fallback (§45).
            'device.auth' => AuthenticateDeviceCredential::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // §36/§37: semua error API WAJIB pakai envelope {success,message,errors} + HTTP status yang benar.
        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('Data yang dikirim tidak valid.', $e->errors(), 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('Autentikasi diperlukan. Silakan login kembali.', [], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error($e->getMessage() ?: 'Anda tidak memiliki izin untuk melakukan ini.', [], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('Data yang diminta tidak ditemukan.', [], 404);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('Endpoint tidak ditemukan.', [], 404);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error('Terlalu banyak percobaan. Coba lagi beberapa saat lagi.', [], 429);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return ApiResponse::error($e->getMessage() ?: 'Permintaan tidak dapat diproses.', [], $e->getStatusCode());
        });
    })->create();
