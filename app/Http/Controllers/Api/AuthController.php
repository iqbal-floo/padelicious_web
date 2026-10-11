<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuthProvider;
use App\Enums\PlayerGender;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\PlayerResource;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\PlayerProfileFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login — issues a Sanctum token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'sometimes|string|max:255',
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        // Same generic failure for unknown email and wrong password, so the
        // response does not reveal which accounts exist.
        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($data['device_name'] ?? 'api');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user->load('player')),
        ]);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('player.stats');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'status' => $user->status?->value,
            'player' => $user->player ? new PlayerResource($user->player) : null,
        ]);
    }

    /**
     * POST /api/v1/auth/logout — revokes only the calling token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * POST /api/v1/auth/register
     *
     * Creates the user, their player profile, and a first API token so the
     * client is logged in immediately.
     */
    public function register(
        RegisterRequest $request,
        PlayerProfileFactory $profiles,
    ): JsonResponse {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data, $profiles) {
            $user = User::query()->create([
                'full_name' => $data['full_name'],
                'email' => $data['email'],
                // Cast to 'hashed' handles the bcrypt.
                'password_hash' => $data['password'],
                'phone' => $data['phone'] ?? null,
                'status' => UserStatus::Active,
            ]);

            $user->forceFill([
                'accepted_terms_at' => ($data['accepted_terms'] ?? true) ? now() : null,
                'last_login_at' => now(),
            ])->save();

            $profiles->forUser(
                $user,
                isset($data['gender']) && $data['gender'] !== null
                    ? PlayerGender::from($data['gender'])
                    : null,
            );

            return $user;
        });

        $token = $user->createToken($data['device_name'] ?? 'api');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user->load('player')),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/auth/google
     *
     * Exchanges a Google access token (obtained natively by the client)
     * for a Padelicious API token. Links to an existing account when the
     * verified email already matches one.
     *
     * Requires GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET to be configured.
     */
    public function google(
        Request $request,
        PlayerProfileFactory $profiles,
    ): JsonResponse {
        $request->validate([
            'access_token' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        if (! config('services.google.client_id')) {
            return response()->json([
                'message' => 'Google sign-in is not configured on this server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->userFromToken($request->string('access_token')->toString());
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'access_token' => ['That Google token could not be verified.'],
            ]);
        }

        $email = $googleUser->getEmail();

        if (! $email) {
            throw ValidationException::withMessages([
                'access_token' => ['Google did not return an email address.'],
            ]);
        }

        $user = DB::transaction(function () use ($googleUser, $email, $profiles) {
            $identity = UserAuthIdentity::query()
                ->where('provider', AuthProvider::Google->value)
                ->where('provider_user_id', $googleUser->getId())
                ->first();

            if ($identity) {
                $user = $identity->user;
            } else {
                // Link to an existing password account with the same email
                // rather than creating a duplicate.
                $user = User::query()->where('email', $email)->first();

                if (! $user) {
                    $user = User::query()->create([
                        'full_name' => $googleUser->getName() ?: $email,
                        'email' => $email,
                        // Social-only account: no local password.
                        'password_hash' => null,
                        'status' => UserStatus::Active,
                    ]);
                    $user->forceFill([
                        'email_verified_at' => now(),
                        'accepted_terms_at' => now(),
                    ])->save();
                }

                UserAuthIdentity::query()->create([
                    'user_id' => $user->id,
                    'provider' => AuthProvider::Google,
                    'provider_user_id' => $googleUser->getId(),
                    'email' => $email,
                    'raw_profile' => [
                        'name' => $googleUser->getName(),
                        'avatar' => $googleUser->getAvatar(),
                    ],
                    'created_at' => now(),
                ]);
            }

            if ($user->status !== UserStatus::Active) {
                throw ValidationException::withMessages([
                    'access_token' => ['This account is not active.'],
                ]);
            }

            $user->forceFill(['last_login_at' => now()])->save();
            $profiles->forUser($user);

            return $user;
        });

        $token = $user->createToken($request->input('device_name', 'google'));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => $this->userPayload($user->load('player')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'status' => $user->status?->value,
            'player' => $user->player ? new PlayerResource($user->player) : null,
        ];
    }
}
