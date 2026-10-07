<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §30 — admin WAJIB menyetujui secara eksplisit sebelum akun Telegram manapun bisa
 * memakai permission apapun (default status PENDING, lihat migration). Tidak ada
 * auto-approve, tidak ada bypass (§7/§16/§27).
 */
class TelegramAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('telegram.manage', TelegramAccount::class);

        $accounts = TelegramAccount::query()
            ->with(['user:id,name,email', 'approvedBy:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Daftar akun Telegram.', $accounts);
    }

    public function approve(Request $request, TelegramAccount $account): JsonResponse
    {
        $this->authorize('telegram.manage', TelegramAccount::class);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', Rule::exists((new User)->getTable(), 'id')],
            'step_up_required' => ['sometimes', 'boolean'],
        ]);

        $account->update([
            'user_id' => $validated['user_id'],
            'status' => 'APPROVED',
            'step_up_required' => $validated['step_up_required'] ?? true,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        activity()->causedBy($request->user())->performedOn($account)
            ->withProperties(['linked_user_id' => $validated['user_id']])
            ->log('telegram_account_approved');

        return ApiResponse::success('Akun Telegram disetujui.', $account->load('user:id,name,email'));
    }

    public function revoke(Request $request, TelegramAccount $account): JsonResponse
    {
        $this->authorize('telegram.manage', TelegramAccount::class);

        $account->update(['status' => 'REVOKED']);

        activity()->causedBy($request->user())->performedOn($account)->log('telegram_account_revoked');

        return ApiResponse::success('Akun Telegram dicabut.', $account);
    }
}
