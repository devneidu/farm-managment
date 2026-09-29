<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\AuthStateResource;
use App\Services\Auth\OtpDelivery;
use App\Services\Auth\OtpService;
use App\Support\Api\ApiHttpException;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Verify email with OTP
     *
     * Submits the 6-digit code emailed to the logged-in user. On success `email_verified_at`
     * is set and the returned state has `next_action = "complete_farm_setup"`. The code is
     * single use; after too many wrong attempts it is invalidated and a new one must be requested.
     * Already-verified accounts get `409 already_verified`.
     *
     * Errors: `401`, `409 already_verified`, `422` (invalid/expired/used code - one uniform message), `429`.
     *
     * @response array{data: AuthStateResource, meta: object, message: string}
     */
    #[Response(status: 409, description: 'Email already verified', type: 'array{message: string, code: "already_verified", request_id: string}')]
    public function verify(VerifyEmailRequest $request, OtpService $otps): JsonResponse
    {
        $user = $request->user();

        $this->ensureUnverified($user);

        if (! $otps->consume($user, OtpPurpose::EmailVerification, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => ['The code is invalid or has expired.']]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        return $this->authState($user, 'Email verified.');
    }

    /**
     * Resend verification OTP
     *
     * Issues a new code and invalidates the previous one. A cooldown (default 60s) applies between
     * sends; hitting it returns `429 too_many_requests` with a `Retry-After` header.
     *
     * Errors: `401`, `409 already_verified`, `429`.
     *
     * @response array{data: array{cooldown_seconds: int}, meta: object, message: string}
     */
    #[Response(status: 409, description: 'Email already verified', type: 'array{message: string, code: "already_verified", request_id: string}')]
    public function resend(Request $request, OtpService $otps, OtpDelivery $delivery): JsonResponse
    {
        $user = $request->user();

        $this->ensureUnverified($user);

        $wait = $otps->cooldownRemaining($user, OtpPurpose::EmailVerification);

        if ($wait > 0) {
            throw new ApiHttpException(429, 'too_many_requests', "Please wait {$wait} seconds before requesting another code.", ['Retry-After' => $wait]);
        }

        $delivery->send($user, OtpPurpose::EmailVerification);

        return ApiResponse::success(
            ['cooldown_seconds' => (int) config('identity.otp.resend_cooldown_seconds')],
            message: 'A new verification code has been sent.',
        );
    }

    private function ensureUnverified($user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new ApiHttpException(409, 'already_verified', 'This email address is already verified.');
        }
    }
}
