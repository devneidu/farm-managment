<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Notifications\OtpNotification;
use Throwable;

/**
 * Issues an OTP and emails it. A mail failure is reported (without the code) but never
 * breaks the request, so responses cannot reveal delivery state; the user can resend.
 */
class OtpDelivery
{
    public function __construct(private readonly OtpService $otps) {}

    public function send(User $user, OtpPurpose $purpose): void
    {
        $code = $this->otps->issueCode($user, $purpose);

        try {
            $user->notify(new OtpNotification($purpose, $code, (int) config('identity.otp.ttl_minutes')));
        } catch (Throwable $e) {
            report(new \RuntimeException('Failed to deliver OTP email ('.$purpose->value.'): '.get_class($e)));
        }
    }
}
