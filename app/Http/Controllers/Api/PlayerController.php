<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlayerController extends Controller
{
    /**
     * GET /api/v1/players
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $players = Player::query()
            ->with('stats')
            ->when(
                $request->filled('search'),
                // ILIKE is backed by the gin_trgm index on display_name.
                fn ($q) => $q->where('display_name', 'ilike', '%'.$request->string('search').'%')
            )
            ->when(
                $request->boolean('registered_only'),
                fn ($q) => $q->whereNotNull('user_id')
            )
            ->orderBy('display_name')
            ->paginate(perPage: min($request->integer('per_page', 25), 100));

        return PlayerResource::collection($players);
    }

    /**
     * GET /api/v1/players/{player}
     */
    public function show(Player $player): PlayerResource
    {
        return new PlayerResource($player->load(['stats', 'clubs']));
    }
}
