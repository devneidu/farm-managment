<?php

namespace App\Support\Access;

/**
 * Notification preferences (per user, per farm, stored on the membership): channel switches (in_app, email) and per-type switches.
 * Everything defaults to ON; only explicit choices are stored. WhatsApp is not a channel (deferred beyond V1).
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

    /** The explicitly stored per-type switches ({code: bool}); a type not listed is on. */
    public static function storedTypes(?array $stored): array
    {
        return array_map('boolval', (array) ($stored['types'] ?? []));
    }

    public static function typeEnabled(?array $stored, string $type): bool
    {
        return self::storedTypes($stored)[$type] ?? true;
    }

    /**
     * The stored JSON after applying a partial update; omitted channels/types keep their value.
     *
     * @param  array{channels?: array<string, mixed>, types?: array<string, mixed>}  $changes
     * @return array{channels: array<string, bool>, types: array<string, bool>}
     */
    public static function merge(?array $stored, array $changes): array
    {
        $current = self::resolve($stored);
        $types = self::storedTypes($stored);
        foreach ($changes['channels'] ?? [] as $channel => $value) {
            $current['channels'][$channel] = (bool) $value;
        }
        foreach ($changes['types'] ?? [] as $type => $value) {
            $types[$type] = (bool) $value;
        }

        return ['channels' => $current['channels'], 'types' => $types];
    }
}
