<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Resources\SessionResource;
use App\Models\Session;
use App\Services\SessionBuilder;
use App\Services\StandingsCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    /**
     * GET /api/v1/sessions?status=active|past
     *
     * Mirrors the Active / Past tabs in the Flutter sessions screen.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => 'sometimes|in:active,past,draft,completed,cancelled',
            'club_id' => 'sometimes|uuid',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $sessions = Session::query()
            ->with(['matchType', 'club', 'host'])
            ->withCount([
                'participants as participant_count' => fn ($q) => $q->where(
                    'status',
                    '!=',
                    ParticipantStatus::Withdrawn->value
                ),
            ])
            ->when($request->filled('club_id'), fn ($q) => $q->where('club_id', $request->string('club_id')))
            ->when($request->filled('status'), function ($q) use ($request) {
                return match ($request->string('status')->toString()) {
                    // "Active" groups draft + active, as the app's tab does.
                    'active' => $q->whereIn('status', [
                        SessionStatus::Draft->value,
                        SessionStatus::Active->value,
                    ]),
                    'past' => $q->whereIn('status', [
                        SessionStatus::Completed->value,
                        SessionStatus::Cancelled->value,
                    ]),
                    default => $q->where('status', $request->string('status')),
                };
            })
            ->orderByDesc('scheduled_date')
            ->paginate(perPage: min($request->integer('per_page', 25), 100));

        return SessionResource::collection($sessions);
    }

    /**
     * GET /api/v1/sessions/{session}
     */
    public function show(Session $session): SessionResource
    {
        $session->load([
            'matchType',
            'club',
            'host',
            'participants' => fn ($q) => $q
                ->where('status', '!=', ParticipantStatus::Withdrawn->value)
                ->orderBy('joined_at'),
            'participants.player',
        ]);

        return new SessionResource($session);
    }

    /**
     * POST /api/v1/sessions
     */
    public function store(StoreSessionRequest $request, SessionBuilder $builder): JsonResponse
    {
        $player = $request->user()->player;

        if (! $player) {
            return response()->json(
                ['message' => 'Your account has no player profile yet.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $data = $request->safe()->except('participants');
        $participants = $request->input('participants', []);

        $session = $builder->create($data, $participants, $player->id);

        return (new SessionResource($session->load([
            'matchType', 'club', 'host', 'participants.player',
        ])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/sessions/{session}/complete
     */
    public function complete(
        Request $request,
        Session $session,
        StandingsCalculator $standings,
    ): SessionResource {
        $this->authorize('manage', $session);

        $standings->recalculate($session);

        $session->forceFill([
            'status' => SessionStatus::Completed,
            'completed_at' => now(),
        ])->save();

        return new SessionResource($session->load(['matchType', 'club', 'host']));
    }

    /**
     * DELETE /api/v1/sessions/{session} — cancels rather than deletes, so
     * the match history survives.
     */
    public function cancel(Request $request, Session $session): SessionResource
    {
        $this->authorize('manage', $session);

        $session->forceFill([
            'status' => SessionStatus::Cancelled,
            'completed_at' => now(),
        ])->save();

        return new SessionResource($session->load(['matchType', 'club', 'host']));
    }
}
