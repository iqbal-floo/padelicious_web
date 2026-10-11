<?php

namespace App\Http\Controllers\Api;

use App\Enums\ParticipantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreParticipantRequest;
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

        $participant->forceFill(['status' => ParticipantStatus::Withdrawn])->save();

        return new SessionParticipantResource($participant);
    }
}
