<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Services\Auth\Google\GoogleAuthUnavailableException;
use App\Services\Auth\Google\InvalidGoogleCredentialException;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The credential rules shared by every sign-in channel: password check, suspension, registration (+ verification OTP) and Google
 * sign-in. It never starts a session or issues a token; the channel's finisher does that (SessionAuthenticator for the web cookie
 * flow, TokenAuthenticator for mobile), so the rules exist exactly once.
 */
class CredentialAuthenticator
{
    public function __construct(
        private readonly OtpDelivery $otps,
        private readonly GoogleAuthService $google,
    ) {}

    /** @throws ApiHttpException 401 invalid_credentials, 403 account_suspended */
    public function attempt(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        $valid = $user && $user->password && Hash::check($password, $user->password);

        if (! $user || ! $user->password) {
            Hash::make(Str::random(16)); // comparable work for unknown/passwordless accounts
        }

        if (! $valid) {
            throw new ApiHttpException(401, 'invalid_credentials', 'These credentials do not match our records.');
        }

        if ($user->isSuspended()) {
            throw new ApiHttpException(403, 'account_suspended', 'This account is suspended.');
        }

        return $user;
    }

    /** Creates the UNVERIFIED account and emails the verification OTP. */
    public function register(string $email, string $password): User
    {
        try {
            $user = new User(['email' => $email, 'password' => $password]);
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }

        $this->otps->send($user, OtpPurpose::EmailVerification);

        return $user;
    }

    /**
     * @return array{0: User, 1: bool} the user and whether the account was just created
     *
     * @throws ApiHttpException 401 invalid_google_credential, 403 account_suspended, 503 google_unavailable
     */
    public function google(string $credential): array
    {
        try {
            return $this->google->authenticate($credential);
        } catch (InvalidGoogleCredentialException) {
            throw new ApiHttpException(401, 'invalid_google_credential', 'The Google credential is invalid.');
        } catch (GoogleAuthUnavailableException) {
            throw new ApiHttpException(503, 'google_unavailable', 'Google sign-in is currently unavailable.');
        }
    }
}
