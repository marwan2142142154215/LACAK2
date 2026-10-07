<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * GET /api/v1/auth/me — identitas user yang sedang login + role/permission (untuk UI RBAC).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success('Profil user.', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}
