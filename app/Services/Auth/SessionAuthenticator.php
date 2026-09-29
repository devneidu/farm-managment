<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Api\ApiHttpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * First-party SPA session login/logout (Sanctum stateful cookie authentication).
 */
class SessionAuthenticator
{
    /** Call before creating any account so a non-browser request leaves no side effects. */
    public function assertStateful(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new ApiHttpException(400, 'stateful_request_required', 'Send this request from the first-party frontend with credentials and an Origin header.');
        }
    }

    public function login(Request $request, User $user): void
    {
        $this->assertStateful($request);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }

    public function logout(Request $request): void
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete(); // future mobile/API clients
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
