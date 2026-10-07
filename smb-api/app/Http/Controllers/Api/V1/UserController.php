<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §40 — daftar user minimal (nama/email/role), dibutuhkan untuk UI admin yang perlu
 * memilih user (mis. menautkan akun Telegram ke user, PHASE 18). Bukan user management
 * penuh (create/update/delete user ada di luar scope spec yang sudah dikerjakan sejauh ini).
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('users.manage', User::class);

        $users = User::query()
            ->where('is_active', true)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        $users->getCollection()->transform(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ]);

        return ApiResponse::paginated('Daftar user.', $users);
    }
}
