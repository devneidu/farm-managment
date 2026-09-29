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
        $app = config('app.name');

        [$subject, $intro] = match ($this->purpose) {
            OtpPurpose::PasswordReset => ["Your {$app} password reset code", 'Use this code to reset your password.'],
            default => ["Verify your {$app} email", 'Use this code to verify your email address.'],
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello,')
            ->line($intro)
            ->line("**{$this->code}**")
            ->line("This code expires in {$this->ttlMinutes} minutes. If you did not request it, you can ignore this email.");
    }
}
