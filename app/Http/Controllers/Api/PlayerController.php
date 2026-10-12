<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlayerGender;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlayerRequest;
use App\Http\Resources\PlayerResource;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

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
                // ILIKE is backed by the gin_trgm index on display_name;
                // the username lookup lets "@handle" find the right Iqbal.
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('display_name', 'ilike', '%'.$request->string('search').'%')
                    ->orWhere('username', 'ilike', '%'.ltrim($request->string('search')->toString(), '@').'%'))
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
     * POST /api/v1/players — a guest player added by hand.
     *
     * No account, no email: just a name, a unique username and (optionally)
     * a gender so the player can be put on a roster and into a mixed format.
     */
    public function store(StorePlayerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $name = $data['display_name'];

        $player = Player::query()->create([
            'display_name' => $name,
            'username' => $data['username'],
            'initials' => $data['initials'] ?? $this->initials($name),
            'avatar_color_index' => abs(crc32($data['username'])) % 8,
            'gender' => $data['gender'] ?? PlayerGender::Undisclosed,
            'skill_level' => $data['skill_level'] ?? null,
            'skill_level_label' => $data['skill_level_label'] ?? null,
        ]);

        // player_id is guarded on PlayerStat; the relation sets it directly.
        $player->stats()->firstOrCreate([]);

        // is_guest is a stored generated column: only present after a re-read.
        return (new PlayerResource($player->refresh()->load('stats')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Two characters: initials of the first two words, else the first two
     * letters — the same rule PlayerProfileFactory uses for sign-ups.
     */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(str_pad(mb_substr($words[0] ?? 'PL', 0, 2), 2, 'X'));
    }

    /**
     * GET /api/v1/players/{player}
     */
    public function show(Player $player): PlayerResource
    {
        return new PlayerResource($player->load(['stats', 'clubs']));
    }
}
