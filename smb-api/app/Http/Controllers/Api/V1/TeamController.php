<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Team\StoreTeamRequest;
use App\Http\Requests\Team\UpdateTeamRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('teams.manage', Team::class);

        $perPage = min((int) $request->integer('per_page', 15), 100);

        $teams = Team::query()
            ->with('site:id,name,code')
            ->when($request->filled('site_id'), fn ($q) => $q->where('site_id', $request->input('site_id')))
            ->orderBy('name')
            ->paginate($perPage);

        return ApiResponse::paginated('Daftar team.', $teams);
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $team = Team::create($request->validated() + ['created_by' => $request->user()->id]);

        return ApiResponse::success('Team berhasil dibuat.', $team, 201);
    }

    public function show(Team $team): JsonResponse
    {
        $this->authorize('teams.manage', Team::class);

        return ApiResponse::success('Detail team.', $team->load('site'));
    }

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        $team->update($request->validated());

        return ApiResponse::success('Team berhasil diperbarui.', $team);
    }

    public function destroy(Request $request, Team $team): JsonResponse
    {
        $this->authorize('teams.manage', Team::class);

        $team->delete();

        activity()->causedBy($request->user())->performedOn($team)->log('team_deleted');

        return ApiResponse::success('Team berhasil dihapus.');
    }
}
