<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends an OTP by email (sent synchronously on purpose: a queued notification would
 * persist the usable code in the jobs table). Transport is chosen by MAIL_* env.
 */
class OtpNotification extends Notification
{
    public function __construct(
        public readonly OtpPurpose $purpose,
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('identity.brand_name');

        [$subject, $lines, $ignore] = match ($this->purpose) {
            OtpPurpose::PasswordReset => [
                "Reset your {$brand} password",
                ["We received a request to reset the password for your {$brand} account.", 'Use the code below to continue.'],
                "If you didn't request a password reset, you can safely ignore this email. Your password will not change.",
            ],
            default => [
                "Verify your {$brand} account",
                ["Welcome to {$brand}.", 'Use the verification code below to confirm your email address and continue setting up your farm.'],
                "If you didn't create a {$brand} account, you can safely ignore this email.",
            ],
        };

        return (new MailMessage)
            ->subject($subject)
            ->view(['html' => 'mail.otp', 'text' => 'mail.otp-text'], [
                'brand' => $brand,
                'subject' => $subject,
                'lines' => $lines,
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
                'ignore' => $ignore,
            ]);
    }
}
