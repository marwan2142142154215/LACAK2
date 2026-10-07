<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * GET /api/v1/auth/sessions — daftar device/session aktif milik user ini (§14).
     */
    public function index(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'created_at' => $token->created_at,
                'is_current' => $token->id === $currentId,
            ]);

        return ApiResponse::success('Daftar session.', $sessions);
    }

    /**
     * DELETE /api/v1/auth/sessions/{tokenId} — revoke session lain (§14).
     * §58 anti-IDOR: query dibatasi ke tokens() milik user yang login, bukan Model::find() global.
     */
    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->where('id', $tokenId)->first();

        if (! $token) {
            return ApiResponse::error('Session tidak ditemukan.', [], 404);
        }

        $token->delete();

        activity()->causedBy($request->user())->withProperties(['token_id' => $tokenId])->log('session_revoked');

        return ApiResponse::success('Session berhasil diakhiri.');
    }
}
