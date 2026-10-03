<?php

namespace App\Services\Notifications;

use App\Models\FarmNotification;
use App\Notifications\FarmAlertNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/** Records the outcome of the email copy on the notification (delivery state is the notification's, never the source's). */
class EmailDeliveryTracker
{
    public function sent(NotificationSent $event): void
    {
        if ($event->notification instanceof FarmAlertNotification && $event->channel === 'mail') {
            FarmNotification::whereKey($event->notification->notificationId)->update(['email_status' => 'sent', 'emailed_at' => now()]);
        }
    }

    public function failed(NotificationFailed $event): void
    {
        if ($event->notification instanceof FarmAlertNotification && $event->channel === 'mail') {
            FarmNotification::whereKey($event->notification->notificationId)->update(['email_status' => 'failed']);
        }
    }
}
