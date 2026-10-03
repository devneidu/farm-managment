<?php

namespace App\Notifications;

use App\Models\FarmNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email copy of an in-app notification (sent only when the user's email channel is on). Queued on the database queue; it carries only the
 * notification id and reads the record at send time, so the email always matches what the inbox shows.
 */
class FarmAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $notificationId)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $n = FarmNotification::with('farm:id,name')->findOrFail($this->notificationId);
        $farm = $n->farm?->name ?? config('app.name');

        return (new MailMessage)
            ->subject("{$farm}: {$n->title}")
            ->greeting('Hello,')
            ->line($n->message)
            ->action('Open notifications', config('identity.invitations.frontend_url').'/notifications')
            ->line('You can choose which alerts you receive in your notification settings.');
    }
}
