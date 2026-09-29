<?php

namespace App\Support\Access;

/**
 * Notification preferences shell (per user, per farm, stored on the membership).
 * Only channel on/off switches exist; the notification engine and WhatsApp are later phases.
 */
final class NotificationPreferences
{
    public const CHANNELS = ['in_app', 'email'];

    /** @return array{channels: array<string, bool>} */
    public static function resolve(?array $stored): array
    {
        $channels = [];

        foreach (self::CHANNELS as $channel) {
            $channels[$channel] = (bool) ($stored['channels'][$channel] ?? true);
        }

        return ['channels' => $channels];
    }
}
