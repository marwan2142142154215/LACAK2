<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceNetworkViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §107/§108 — Network Monitoring (Web Dashboard & SMB Master, read-only). Filter
 * `status=open|resolved|all` — default `open` (yang paling relevan buat operator).
 */
class NetworkViolationController extends Controller
{
    /** GET /api/v1/network-violations */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('network.view', DeviceNetworkViolation::class);

        $status = $request->input('status', 'open');

        $violations = DeviceNetworkViolation::query()
            ->with(['device:id,name,site_id', 'site:id,name'])
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->input('site_id')))
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->input('device_id')))
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->orderByDesc('last_seen_at')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return ApiResponse::paginated('Daftar network violation.', $violations);
    }
}
