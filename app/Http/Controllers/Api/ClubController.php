<?php

namespace App\Http\Controllers\Api;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClubResource;
use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClubController extends Controller
{
    /**
     * GET /api/v1/clubs
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $clubs = Club::query()
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where('name', 'ilike', '%'.$request->string('search').'%')
            )
            ->when(
                $request->filled('city'),
                fn ($q) => $q->where('city', $request->string('city'))
            )
            ->orderBy('name')
            ->paginate(perPage: min($request->integer('per_page', 25), 100));

        return ClubResource::collection($clubs);
    }

    /**
     * GET /api/v1/clubs/{club}
     */
    public function show(Club $club): ClubResource
    {
        $club->load([
            'owner',
            'members' => fn ($q) => $q->wherePivot('status', MembershipStatus::Active->value),
        ]);

        return new ClubResource($club);
    }
}
