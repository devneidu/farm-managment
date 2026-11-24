<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token finisher for native/mobile clients (the counterpart of SessionAuthenticator, which finishes the web cookie flow).
 * Each sign-in creates one expiring personal access token named after the device.
 */
class TokenAuthenticator
{
    /** @return array{token: string, token_type: 'Bearer', expires_at: string} */
    public function issue(User $user, string $deviceName): array
    {
        $days = max(1, (int) config('identity.mobile.token_ttl_days', 90));
        $new = $user->createToken($deviceName, ['*'], now()->addDays($days));

        return [
            'token' => $new->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $new->accessToken->expires_at->toIso8601String(),
        ];
    }

    /** The user's live (unexpired) tokens, newest first. */
    public function devices(User $user)
    {
        return $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /** The token the current request authenticated with, if any (null for a cookie session). */
    public function current(User $user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** Revokes every token and every web session of the user (a signed-in cookie session may be kept). */
    public function revokeAll(User $user, ?string $keepSessionId = null): void
    {
        DB::transaction(function () use ($user, $keepSessionId) {
            $user->tokens()->delete();
            $this->deleteSessions($user, $keepSessionId);
        });
    }

    public function deleteSessions(User $user, ?string $keepSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $q = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id);

        if ($keepSessionId !== null) {
            $q->where('id', '!=', $keepSessionId);
        }

        $q->delete();
    }
}
