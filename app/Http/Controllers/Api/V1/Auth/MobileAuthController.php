<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\MobileGoogleLoginRequest;
use App\Http\Requests\Auth\MobileLoginRequest;
use App\Http\Requests\Auth\MobileRegisterRequest;
use App\Http\Resources\AuthStateResource;
use App\Models\User;
use App\Services\Auth\CredentialAuthenticator;
use App\Services\Auth\TokenAuthenticator;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Token sign-in for native apps. Same credential rules as the web endpoints (CredentialAuthenticator), but no cookie, CSRF or
 * Origin requirement and no session: the response carries a Bearer token instead.
 */
class MobileAuthController extends Controller
{
    /**
     * Mobile: log in with email and password
     *
     * Returns a Bearer token (named after `device_name`, expiring after `MOBILE_TOKEN_TTL_DAYS`, default 90) plus the
     * same payload as `GET /auth/me` - flat inside `data`: `token`, `token_type` ("Bearer"), `expires_at`, then `authenticated`,
     * `next_action`, `user`, `farm`, `farms`, ... Send `Authorization: Bearer <token>` afterwards; no cookies, no CSRF.
     * ALWAYS read `next_action` (`verify_email`, `complete_farm_setup`, ...).
     *
     * Errors: `401 invalid_credentials`, `403 account_suspended`, `422` validation (`device_name` required, max 100), `429`.
     *
     * @unauthenticated
     *
     * @response array{data: array{token: string, token_type: 'Bearer', expires_at: string}&AuthStateResource, meta: object, message: string}
     */
    public function login(MobileLoginRequest $request, CredentialAuthenticator $credentials, TokenAuthenticator $tokens): JsonResponse
    {
        $user = $credentials->attempt($request->validated('email'), $request->validated('password'));

        return $this->respond($tokens, $request, $user, $request->validated('device_name'), 'Logged in.');
    }

    /**
     * Mobile: register with email and password
     *
     * Creates an UNVERIFIED account, emails the 6-digit verification OTP and returns a Bearer token plus the auth state
     * (`next_action = "verify_email"`). Verify with `POST /auth/email/verify` using the token.
     *
     * Errors: `422` validation (duplicate email, weak password, mismatch, `device_name`), `429`.
     *
     * @unauthenticated
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: array{token: string, token_type: "Bearer", expires_at: string}&\App\Http\Resources\AuthStateResource, meta: object, message: string}')]
    public function register(MobileRegisterRequest $request, CredentialAuthenticator $credentials, TokenAuthenticator $tokens): JsonResponse
    {
        $user = $credentials->register($request->validated('email'), $request->validated('password'));

        return $this->respond($tokens, $request, $user, $request->validated('device_name'), 'Account created. Enter the verification code sent to your email.', 201);
    }

    /**
     * Mobile: continue with Google
     *
     * Post the Google ID token (`credential`) obtained by the native Google sign-in SDK. Accepted audiences are the client ids
     * in `GOOGLE_CLIENT_IDS` (web, Android, iOS); an `azp` claim must also be one of them. Behaves like `POST /auth/google`
     * (linking, no OTP for verified Google emails) and returns a Bearer token plus the auth state. `201` for a new account,
     * `200` otherwise (`meta.created`).
     *
     * Errors: `401 invalid_google_credential`, `403 account_suspended`, `422`, `429`, `503 google_unavailable`.
     *
     * @unauthenticated
     */
    public function google(MobileGoogleLoginRequest $request, CredentialAuthenticator $credentials, TokenAuthenticator $tokens): JsonResponse
    {
        [$user, $created] = $credentials->google($request->validated('credential'));

        return $this->respond($tokens, $request, $user, $request->validated('device_name'), 'Signed in with Google.', $created ? 201 : 200, ['created' => $created]);
    }

    private function respond(TokenAuthenticator $tokens, Request $request, User $user, string $deviceName, string $message, int $status = 200, array $meta = []): JsonResponse
    {
        $token = $tokens->issue($user, $deviceName);

        return ApiResponse::success($token + (new AuthStateResource($user))->resolve($request), $meta, $message, $status);
    }
}
