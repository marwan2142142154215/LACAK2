<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Site;
use App\Models\SiteNetworkPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §98/§107 — CRUD whitelist jaringan per Site. Unique constraint di sisi aplikasi
 * (bukan DB) sengaja TIDAK dipaksakan ketat — admin boleh punya beberapa entri IP/CIDR
 * yang saling tumpang tindih untuk Site yang sama (union-of-allowed, bukan konflik).
 */
class SiteNetworkPolicyController extends Controller
{
    /** GET /api/v1/sites/{site}/network-policies */
    public function index(Site $site): JsonResponse
    {
        $this->authorize('network.manage', SiteNetworkPolicy::class);

        return ApiResponse::success(
            'Daftar network policy.',
            $site->networkPolicies()->orderByDesc('created_at')->get(),
        );
    }

    /** POST /api/v1/sites/{site}/network-policies */
    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorize('network.manage', SiteNetworkPolicy::class);

        $validated = $request->validate([
            'network_type' => ['required', Rule::in(['IP', 'CIDR'])],
            'value' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $policy = $site->networkPolicies()->create($validated + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        activity()->causedBy($request->user())->performedOn($policy)
            ->withProperties(['site_id' => $site->id, 'value' => $policy->value])
            ->log('network_policy_created');

        return ApiResponse::success('Network policy dibuat.', $policy, 201);
    }

    /** PATCH /api/v1/sites/{site}/network-policies/{policy} */
    public function update(Request $request, Site $site, SiteNetworkPolicy $policy): JsonResponse
    {
        $this->authorize('network.manage', SiteNetworkPolicy::class);
        abort_if($policy->site_id !== $site->id, 404);

        $validated = $request->validate([
            'value' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $policy->update($validated + ['updated_by' => $request->user()->id]);

        return ApiResponse::success('Network policy diperbarui.', $policy);
    }

    /** DELETE /api/v1/sites/{site}/network-policies/{policy} */
    public function destroy(Request $request, Site $site, SiteNetworkPolicy $policy): JsonResponse
    {
        $this->authorize('network.manage', SiteNetworkPolicy::class);
        abort_if($policy->site_id !== $site->id, 404);

        $policy->delete();

        activity()->causedBy($request->user())->performedOn($policy)->log('network_policy_deleted');

        return ApiResponse::success('Network policy dihapus.');
    }
}
