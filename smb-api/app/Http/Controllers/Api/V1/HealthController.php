<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /api/v1/health — §52. Dipakai monitoring eksternal & nantinya "SMB Doctor" (§77).
     * TIDAK memalsukan OK: setiap check benar-benar menghubungi service terkait.
     */
    public function __invoke(): JsonResponse
    {
        $checks = [
            'laravel' => ['status' => 'OK'],
            'postgresql' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'storage' => $this->checkStorage(),
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['status'] === 'OK');

        return ApiResponse::success(
            $allOk ? 'SYSTEM READY' : 'SYSTEM DEGRADED',
            ['overall' => $allOk ? 'READY' : 'DEGRADED', 'checks' => $checks],
            $allOk ? 200 : 503,
        );
    }

    protected function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return ['status' => 'OK'];
        } catch (Throwable $e) {
            return ['status' => 'FAIL', 'reason' => $e->getMessage()];
        }
    }

    protected function checkRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['status' => 'OK'];
        } catch (Throwable $e) {
            return ['status' => 'FAIL', 'reason' => $e->getMessage()];
        }
    }

    protected function checkStorage(): array
    {
        try {
            $path = storage_path('framework/health-check.tmp');
            file_put_contents($path, 'ok');
            unlink($path);

            return ['status' => 'OK'];
        } catch (Throwable $e) {
            return ['status' => 'FAIL', 'reason' => $e->getMessage()];
        }
    }
}
