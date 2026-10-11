<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\PlayerResource;
use App\Services\PlayerProfileFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * PATCH /api/v1/profile
     *
     * Updates the signed-in user and their player profile. This is how an
     * existing account sets its gender, which the mixed formats require.
     */
    public function update(
        UpdateProfileRequest $request,
        PlayerProfileFactory $profiles,
    ): JsonResponse {
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data, $profiles) {
            $userFields = array_intersect_key($data, array_flip(['full_name', 'phone']));
            if ($userFields !== []) {
                $user->forceFill($userFields)->save();
            }

            // Older accounts may predate automatic profile creation.
            $player = $user->player ?? $profiles->forUser($user);

            $playerFields = array_intersect_key($data, array_flip([
                'display_name', 'gender', 'skill_level',
                'skill_level_label', 'avatar_color_index',
            ]));

            if ($playerFields !== []) {
                $player->forceFill($playerFields)->save();
            }
        });

        $user->refresh()->load('player.stats');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'status' => $user->status?->value,
            'player' => $user->player ? new PlayerResource($user->player) : null,
        ]);
    }
}
