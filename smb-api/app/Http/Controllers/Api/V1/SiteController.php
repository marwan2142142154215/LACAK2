<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\StoreSiteRequest;
use App\Http\Requests\Site\UpdateSiteRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    /**
     * GET /api/v1/sites — §38 pagination wajib untuk data besar.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('sites.manage', Site::class);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $sites = Site::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderBy('name')
            ->paginate($perPage);

        return ApiResponse::paginated('Daftar site.', $sites);
    }

    public function store(StoreSiteRequest $request): JsonResponse
    {
        $site = Site::create($request->validated() + ['created_by' => $request->user()->id]);

        return ApiResponse::success('Site berhasil dibuat.', $site, 201);
    }

    public function show(Request $request, Site $site): JsonResponse
    {
        $this->authorize('sites.manage', Site::class);

        return ApiResponse::success('Detail site.', $site->load('teams'));
    }

    public function update(UpdateSiteRequest $request, Site $site): JsonResponse
    {
        $site->update($request->validated());

        return ApiResponse::success('Site berhasil diperbarui.', $site);
    }

    public function destroy(Request $request, Site $site): JsonResponse
    {
        $this->authorize('sites.manage', Site::class);

        // §49: delete wajib ada confirmation — ditegakkan di sisi FE (dialog), BE tetap soft-delete
        // supaya reversibel & audit trail (device/team yang terkait tidak ikut terhapus — restrict).
        $site->delete();

        activity()->causedBy($request->user())->performedOn($site)->log('site_deleted');

        return ApiResponse::success('Site berhasil dihapus.');
    }
}
