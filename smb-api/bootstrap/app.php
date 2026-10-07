<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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

        // Spatie Laravel Permission (§40) — alias middleware tidak lagi auto-register di Laravel 11+.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // §43: autentikasi device (bukan Sanctum user) untuk endpoint HTTPS fallback (§45).
            'device.auth' => \App\Http\Middleware\AuthenticateDeviceCredential::class,
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
