<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Invitation email (sent synchronously on purpose: the plaintext token must not be
 * persisted in the jobs table). Sent to an on-demand mail route: the invitee may not have an account yet.
 */
class FarmInvitationNotification extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $farmName,
        public readonly ?string $inviterName,
        public readonly string $roleLabel,
        public readonly Carbon $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function acceptUrl(): string
    {
        return config('identity.invitations.frontend_url')
            .config('identity.invitations.accept_path')
            .'?token='.urlencode($this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');
        $who = $this->inviterName ?: 'A farm owner';
        $expires = $this->expiresAt->copy()->timezone(config('app.default_farm_timezone'))->toDayDateTimeString();

        return (new MailMessage)
            ->subject("You have been invited to join {$this->farmName} on {$app}")
            ->greeting('Hello,')
            ->line("{$who} invited you to join **{$this->farmName}** as **{$this->roleLabel}**.")
            ->action('Accept invitation', $this->acceptUrl())
            ->line("Sign in (or create an account) with this email address to accept. The invitation expires on {$expires}.")
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
