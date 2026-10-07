<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Format response standar seluruh API (§36):
 *   sukses: {"success": true,  "message": "...", "data": {...}}
 *   gagal : {"success": false, "message": "...", "errors": {...}}
 */
class ApiResponse
{
    public static function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data ?? [],
        ], $status);
    }

    public static function error(string $message, mixed $errors = null, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?? [],
        ], $status);
    }

    public static function paginated(string $message, $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }
}
