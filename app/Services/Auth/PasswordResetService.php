<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Password recovery: forgot (OTP by email) -> verify OTP (issues single-use reset
 * authorization) -> reset. Nothing here reveals whether an email is registered.
 */
class PasswordResetService
{
    public function __construct(
        private readonly OtpService $otps,
        private readonly OtpDelivery $delivery,
    ) {}

    /** Silently does nothing for unknown emails, suspended accounts and cooldown hits. */
    public function sendOtp(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || $user->isSuspended()) {
            return;
        }

        if ($this->otps->cooldownRemaining($user, OtpPurpose::PasswordReset) > 0) {
            return;
        }

        $this->delivery->send($user, OtpPurpose::PasswordReset);
    }

    /** Exchange a valid reset OTP for a short-lived single-use reset authorization token. */
    public function verifyOtp(string $email, string $code): string
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $this->otps->consume($user, OtpPurpose::PasswordReset, $code)) {
            throw $this->invalid('code', 'The code is invalid or has expired.');
        }

        return $this->otps->issueResetAuthorization($user);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $this->otps->consume($user, OtpPurpose::PasswordResetAuthorization, $token)) {
            throw $this->invalid('reset_token', 'The reset authorization is invalid or has expired.');
        }

        DB::transaction(function () use ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => null])->save();

            $this->otps->invalidate($user, OtpPurpose::PasswordReset);
            $this->otps->invalidate($user, OtpPurpose::PasswordResetAuthorization);

            // End every existing login: Sanctum API tokens and (database driver) browser sessions.
            $user->tokens()->delete();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
    }

    private function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
