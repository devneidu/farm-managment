<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\CredentialAuthenticator;
use App\Services\Auth\SessionAuthenticator;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Register with email and password
     *
     * Creates an UNVERIFIED account, starts the browser session and emails a 6-digit
     * verification OTP. The response is the standard auth state with
     * `next_action = "verify_email"`. Requires CSRF initialisation and a first-party Origin.
     * Native apps use `POST /auth/mobile/register` instead.
     *
     * Errors: `422` validation (duplicate email, weak password, mismatch), `429` rate limited.
     *
     * @unauthenticated
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\AuthStateResource, meta: object, message: string}')]
    public function __invoke(RegisterRequest $request, CredentialAuthenticator $credentials, SessionAuthenticator $sessions): JsonResponse
    {
        $sessions->assertStateful($request);

        $user = $credentials->register($request->validated('email'), $request->validated('password'));

        $sessions->login($request, $user);

        return $this->authState($user, 'Account created. Enter the verification code sent to your email.', 201);
    }
}
