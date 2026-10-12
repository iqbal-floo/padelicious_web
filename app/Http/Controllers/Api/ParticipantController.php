<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\ParticipantStatus;
use App\Enums\ParticipantTag;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParticipantRequest;
use App\Http\Requests\UpdateParticipantRequest;
use App\Http\Resources\SessionParticipantResource;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Services\SessionBuilder;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends Controller
{
    /**
     * POST /api/v1/sessions/{session}/participants
     */
    public function store(
        StoreParticipantRequest $request,
        Session $session,
        SessionBuilder $builder,
    ): Response {
        $this->authorize('manage', $session);

        try {
            $participant = $builder->addParticipant(
                $session,
                $request->string('player_id')->toString(),
                $request->input('role', 'player'),
                $request->input('source', 'manual'),
            );
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        }

        return (new SessionParticipantResource($participant->load('player')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/sessions/{session}/participants/{participant}
     *
     * Host-only: confirm or waitlist a player, and set their tags (host,
     * no_show). The creator always stays a host, so a session can never be
     * left without anyone able to manage it.
     */
    public function update(
        UpdateParticipantRequest $request,
        Session $session,
        SessionParticipant $participant,
    ): Response {
        $this->authorize('manage', $session);

        abort_unless($participant->session_id === $session->id, Response::HTTP_NOT_FOUND);

        if ($participant->status === ParticipantStatus::Withdrawn) {
            return response()->json(
                ['message' => 'This player has withdrawn. Add them again to change their status.'],
                Response::HTTP_CONFLICT,
            );
        }

        $data = $request->validated();
        $isCreator = $participant->player_id === $session->host_player_id;

        if (array_key_exists('tags', $data)) {
            $tags = array_values(array_unique($data['tags']));

            if ($isCreator && ! in_array(ParticipantTag::Host->value, $tags, true)) {
                return response()->json(
                    ['message' => 'The session creator is always a host.', 'errors' => ['tags' => ['The session creator is always a host.']]],
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            $participant->tags = $tags;
            // Keep the legacy role column in step with the host tag.
            $participant->role = in_array(ParticipantTag::Host->value, $tags, true)
                ? ParticipantRole::Host
                : ($participant->role === ParticipantRole::Host ? ParticipantRole::Player : $participant->role);
        }

        if (array_key_exists('status', $data)) {
            $participant->status = ParticipantStatus::from($data['status']);
        }

        $participant->save();

        return (new SessionParticipantResource($participant->refresh()->load('player')))->response();
    }

    /**
     * POST /api/v1/sessions/{session}/join
     *
     * The signed-in player asks to join. They appear as `requested` until a
     * host confirms or waitlists them.
     */
    public function join(Request $request, Session $session, SessionBuilder $builder): Response
    {
        $player = $request->user()->player;

        if (! $player) {
            return response()->json(['message' => 'Your account has no player profile yet.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $participant = $builder->addParticipant(
                $session,
                $player->id,
                ParticipantRole::Player,
                ParticipantSource::Manual,
                ParticipantStatus::Requested,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'You are already on this session.'], Response::HTTP_CONFLICT);
        }

        return (new SessionParticipantResource($participant->load('player')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/sessions/{session}/participants/{participant}
     *
     * Withdraws rather than deletes, so match history stays intact.
     */
    public function destroy(
        Request $request,
        Session $session,
        SessionParticipant $participant,
    ): SessionParticipantResource {
        $this->authorize('manage', $session);

        abort_unless($participant->session_id === $session->id, Response::HTTP_NOT_FOUND);

        if ($participant->player_id === $session->host_player_id) {
            abort(Response::HTTP_CONFLICT, 'The session creator cannot be removed.');
        }

        $participant->forceFill(['status' => ParticipantStatus::Withdrawn])->save();

        return new SessionParticipantResource($participant);
    }
}
