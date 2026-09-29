<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Purpose-scoped, single-use, expiring, attempt-limited secrets (6-digit OTPs and
 * the reset authorization token). Only an HMAC of the secret is stored; the
 * plaintext is returned once to the caller and never persisted or logged.
 */
class OtpService
{
    /** Issue a 6-digit OTP, superseding any active code for the same user+purpose. */
    public function issueCode(User $user, OtpPurpose $purpose): string
    {
        $length = (int) config('identity.otp.length');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        $this->store($user, $purpose, $code, now()->addMinutes((int) config('identity.otp.ttl_minutes')));

        return $code;
    }

    /** Issue the short-lived single-use password-reset authorization token. */
    public function issueResetAuthorization(User $user): string
    {
        $token = Str::random(64);

        $this->store(
            $user,
            OtpPurpose::PasswordResetAuthorization,
            $token,
            now()->addMinutes((int) config('identity.reset_authorization_ttl_minutes')),
        );

        return $token;
    }

    /**
     * Consume a secret. Returns false (without saying why) for missing, expired,
     * consumed, superseded, exhausted or wrong secrets. Wrong guesses are counted.
     */
    public function consume(User $user, OtpPurpose $purpose, string $secret): bool
    {
        return DB::transaction(function () use ($user, $purpose, $secret) {
            $record = $this->activeQuery($user, $purpose)->latest('created_at')->lockForUpdate()->first();

            if (! $record || $record->expires_at->isPast() || $record->attempts >= (int) config('identity.otp.max_attempts')) {
                return false;
            }

            $record->increment('attempts');

            if (! hash_equals($record->secret_hash, $this->hash($user, $purpose, $secret))) {
                return false;
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }

    /** Seconds until another code may be issued for this user+purpose; 0 when allowed. */
    public function cooldownRemaining(User $user, OtpPurpose $purpose): int
    {
        $latest = OneTimeCode::where('user_id', $user->id)->where('purpose', $purpose->value)->latest('created_at')->first();

        if (! $latest) {
            return 0;
        }

        $availableAt = $latest->created_at->copy()->addSeconds((int) config('identity.otp.resend_cooldown_seconds'));

        return max(0, (int) ceil(now()->floatDiffInSeconds($availableAt, false)));
    }

    /** Invalidate every active code for the user+purpose. */
    public function invalidate(User $user, OtpPurpose $purpose): void
    {
        $this->activeQuery($user, $purpose)->update(['superseded_at' => now()]);
    }

    private function store(User $user, OtpPurpose $purpose, string $secret, \DateTimeInterface $expiresAt): void
    {
        DB::transaction(function () use ($user, $purpose, $secret, $expiresAt) {
            $this->invalidate($user, $purpose);

            OneTimeCode::create([
                'user_id' => $user->id,
                'purpose' => $purpose->value,
                'secret_hash' => $this->hash($user, $purpose, $secret),
                'expires_at' => $expiresAt,
            ]);
        });
    }

    private function activeQuery(User $user, OtpPurpose $purpose)
    {
        return OneTimeCode::where('user_id', $user->id)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at')
            ->whereNull('superseded_at');
    }

    private function hash(User $user, OtpPurpose $purpose, string $secret): string
    {
        return hash_hmac('sha256', $purpose->value.'|'.$user->id.'|'.$secret, (string) config('app.key'));
    }
}
