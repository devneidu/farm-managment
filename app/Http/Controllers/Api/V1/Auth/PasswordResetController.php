<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetOtpRequest;
use App\Services\Auth\PasswordResetService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    /**
     * Request a password-reset OTP
     *
     * Always returns the same `200` response whether or not the email is registered
     * (no account enumeration). If the account exists a 6-digit reset OTP is emailed
     * (a per-account cooldown applies silently). Strictly rate limited.
     *
     * @unauthenticated
     *
     * @response array{data: null, meta: object, message: string}
     */
    public function forgot(ForgotPasswordRequest $request, PasswordResetService $passwords): JsonResponse
    {
        $passwords->sendOtp($request->validated('email'));

        return ApiResponse::success(null, message: 'If an account exists for that email, a reset code has been sent.');
    }

    /**
     * Verify a password-reset OTP
     *
     * Exchanges the emailed code (single use, wrong attempts are counted) for a `reset_token`
     * valid for a short time (default 15 minutes) and usable exactly once at `/auth/password/reset`.
     * Email-verification codes are rejected. Unknown email, wrong, expired or used code all give
     * the same `422`.
     *
     * @unauthenticated
     *
     * @response array{data: array{reset_token: string, expires_in: int}, meta: object, message: string}
     */
    public function verify(VerifyPasswordResetOtpRequest $request, PasswordResetService $passwords): JsonResponse
    {
        $token = $passwords->verifyOtp($request->validated('email'), $request->validated('code'));

        return ApiResponse::success([
            'reset_token' => $token,
            'expires_in' => (int) config('identity.reset_authorization_ttl_minutes') * 60,
        ], message: 'Code verified.');
    }

    /**
     * Reset the password
     *
     * Consumes the `reset_token` (single use) and sets the new password. All existing sessions
     * and API tokens of the account are revoked (browser sessions require SESSION_DRIVER=database),
     * so the user must log in again. The user is NOT logged in by this call.
     *
     * @unauthenticated
     *
     * @response array{data: null, meta: object, message: string}
     */
    public function reset(ResetPasswordRequest $request, PasswordResetService $passwords): JsonResponse
    {
        $passwords->reset(
            $request->validated('email'),
            $request->validated('reset_token'),
            $request->validated('password'),
        );

        return ApiResponse::success(null, message: 'Password updated. You can now log in.');
    }
}
