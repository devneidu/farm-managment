<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The last email a deleted account receives, addressed to the ORIGINAL address (sent on-demand, because by then the user row no longer holds it).
 * It carries no personal data and no link or code.
 */
class AccountDeletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('identity.brand_name');

        return (new MailMessage)
            ->subject("Your {$brand} account was deleted")
            ->line("Your {$brand} account has been deleted and your personal details have been removed.")
            ->line('Farm records you worked on are kept by the farms they belong to, but they no longer show your name.')
            ->line("If you did not ask for this, contact {$brand} support straight away.");
    }
}
