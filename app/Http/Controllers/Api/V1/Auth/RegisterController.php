<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\OtpDelivery;
use App\Services\Auth\SessionAuthenticator;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class RegisterController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Register with email and password
     *
     * Creates an UNVERIFIED account, starts the browser session and emails a 6-digit
     * verification OTP. The response is the standard auth state with
     * `next_action = "verify_email"`. Requires CSRF initialisation and a first-party Origin.
     *
     * Errors: `422` validation (duplicate email, weak password, mismatch), `429` rate limited.
     *
     * @unauthenticated
     */
    #[Response(status: 201, description: 'Created', type: 'array{data: \App\Http\Resources\AuthStateResource, meta: object, message: string}')]
    public function __invoke(RegisterRequest $request, OtpDelivery $otps, SessionAuthenticator $sessions): JsonResponse
    {
        $sessions->assertStateful($request);

        try {
            $user = new User(['email' => $request->validated('email'), 'password' => $request->validated('password')]);
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }

        $sessions->login($request, $user);
        $otps->send($user, OtpPurpose::EmailVerification);

        return $this->authState($user, 'Account created. Enter the verification code sent to your email.', 201);
    }
}
