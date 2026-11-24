<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthStateResource;
use App\Services\Auth\CredentialAuthenticator;
use App\Services\Auth\SessionAuthenticator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function login(LoginRequest $request, CredentialAuthenticator $credentials, SessionAuthenticator $sessions): JsonResponse
    {
        $user = $credentials->attempt($request->validated('email'), $request->validated('password'));

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
