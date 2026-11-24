<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Resources\AuthStateResource;
use App\Services\Auth\CredentialAuthenticator;
use App\Services\Auth\SessionAuthenticator;
use Illuminate\Http\JsonResponse;

class GoogleAuthController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Continue with Google
     *
     * The frontend obtains a Google Identity Services ID token (`credential`) and posts it here.
     * The backend verifies the token's signature, issuer, audience and expiry with Google's keys,
     * then finds/links/creates the local user. Google-verified emails are marked verified and NO
     * application OTP is sent. Returns `201` for a newly created account, `200` otherwise, with
     * the standard auth state (`next_action` is `complete_farm_setup` until a farm exists).
     *
     * An existing email/password account with the same email is linked, not duplicated.
     * Native apps use `POST /auth/mobile/google` instead.
     *
     * Errors: `401 invalid_google_credential`, `403 account_suspended`,
     * `422` (no verified Google email), `429`, `503 google_unavailable`.
     *
     * @unauthenticated
     *
     * @response array{data: AuthStateResource, meta: array{created: bool}, message: string}
     */
    public function __invoke(GoogleLoginRequest $request, CredentialAuthenticator $credentials, SessionAuthenticator $sessions): JsonResponse
    {
        $sessions->assertStateful($request);

        [$user, $created] = $credentials->google($request->validated('credential'));

        $sessions->login($request, $user);

        return $this->authState($user, 'Signed in with Google.', $created ? 201 : 200, ['created' => $created]);
    }
}
