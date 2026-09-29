<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthStateResource;
use App\Models\User;
use App\Services\Auth\SessionAuthenticator;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Log in with email and password
     *
     * Starts the browser session (cookie). ALWAYS read `next_action` from the response:
     * `verify_email` (account unverified - the user is logged in but restricted),
     * `complete_farm_setup`, or `none`.
     *
     * Errors: `401 invalid_credentials` (same response for unknown email and wrong password),
     * `403 account_suspended`, `422` validation, `429` rate limited.
     *
     * @unauthenticated
     *
     * @response array{data: AuthStateResource, meta: object, message: string}
     */
    public function login(LoginRequest $request, SessionAuthenticator $sessions): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $valid = $user && $user->password && Hash::check($request->validated('password'), $user->password);

        if (! $user || ! $user->password) {
            Hash::make(Str::random(16)); // comparable work for unknown/passwordless accounts
        }

        if (! $valid) {
            throw new ApiHttpException(401, 'invalid_credentials', 'These credentials do not match our records.');
        }

        if ($user->isSuspended()) {
            throw new ApiHttpException(403, 'account_suspended', 'This account is suspended.');
        }

        $sessions->login($request, $user);

        return $this->authState($user, 'Logged in.');
    }

    /**
     * Current auth state
     *
     * The single request the frontend needs on load to decide between the login,
     * verify-email, farm-setup and application screens. Returns `401 unauthenticated`
     * when there is no valid session.
     *
     * @response array{data: AuthStateResource, meta: object, message: null}
     */
    public function me(Request $request): JsonResponse
    {
        return $this->authState($request->user());
    }

    /**
     * Log out
     *
     * Ends the current browser session only (other devices are unaffected). Requires CSRF.
     *
     * @response array{data: null, meta: object, message: string}
     */
    public function logout(Request $request, SessionAuthenticator $sessions): JsonResponse
    {
        $sessions->logout($request);

        return ApiResponse::success(null, message: 'Logged out.');
    }
}
