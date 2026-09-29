<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\Google\GoogleIdentity;
use App\Services\Auth\Google\GoogleIdentityVerifier;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoogleAuthService
{
    public function __construct(
        private readonly GoogleIdentityVerifier $verifier,
        private readonly OtpService $otps,
    ) {}

    /**
     * Find, link or create the local user for a verified Google credential.
     *
     * @return array{0: User, 1: bool} the user and whether the account was just created
     */
    public function authenticate(string $credential): array
    {
        $identity = $this->verifier->verify($credential);

        if (! $identity->email || ! $identity->emailVerified) {
            throw ValidationException::withMessages(['credential' => ['The Google account does not have a verified email address.']]);
        }

        [$user, $created] = DB::transaction(fn () => $this->resolve($identity));

        if ($user->isSuspended()) {
            throw new ApiHttpException(403, 'account_suspended', 'This account is suspended.');
        }

        return [$user, $created];
    }

    private function resolve(GoogleIdentity $identity): array
    {
        // 1. Repeat login: the provider subject is the identity, never the (mutable) email.
        $linked = SocialAccount::where('provider', SocialAccount::PROVIDER_GOOGLE)
            ->where('provider_user_id', $identity->subject)->first();

        if ($linked) {
            return [$this->markVerified($linked->user), false];
        }

        // 2. Existing local account with the same (Google-verified) email: link, do not duplicate.
        $user = User::where('email', $identity->email)->lockForUpdate()->first();

        if ($user) {
            if (! $user->hasVerifiedEmail()) {
                // Pre-hijack defence: an unverified password account may have been registered by
                // someone who does not own the mailbox, so its password is discarded.
                $user->forceFill(['password' => null])->save();
                $this->otps->invalidate($user, OtpPurpose::EmailVerification);
            }

            $this->link($user, $identity);

            return [$this->markVerified($user), false];
        }

        // 3. New user (no password, verified by Google).
        $user = new User(['email' => $identity->email, 'name' => $identity->name]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->link($user, $identity);

        return [$user, true];
    }

    private function link(User $user, GoogleIdentity $identity): void
    {
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_user_id' => $identity->subject,
            'email' => $identity->email,
        ]);

        if (! $user->name && $identity->name) {
            $user->forceFill(['name' => $identity->name])->save();
        }
    }

    private function markVerified(User $user): User
    {
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
