<?php

namespace App\Notifications;

use App\Models\FarmMembership;
use App\Models\FarmNotification;
use App\Services\Notifications\NotificationCatalogue;
use App\Support\Access\NotificationPreferences;
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

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly string $notificationId)
    {
        // The V1 database queue shares the notification transaction.
        $this->beforeCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Queue delay must not allow delivery after access or notification consent is revoked. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $n = FarmNotification::find($this->notificationId);
        $membership = $n ? FarmMembership::where('farm_id', $n->farm_id)->where('user_id', $notifiable->id)
            ->active()->with('user')->first() : null;
        $permission = $n ? (NotificationCatalogue::all()[$n->type]['permission'] ?? null) : null;
        $allowed = $channel === 'mail' && $n && $n->user_id === $notifiable->id && $membership && $permission
            && $membership->user && ! $membership->user->isSuspended() && $membership->user->hasVerifiedEmail()
            && $membership->can($permission)
            && NotificationPreferences::resolve($membership->notification_preferences)['channels']['email']
            && NotificationPreferences::typeEnabled($membership->notification_preferences, $n->type);
        if (! $allowed && $n && $n->email_status === 'queued') {
            $n->forceFill(['email_status' => 'skipped'])->save();
        }

        return (bool) $allowed;
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
