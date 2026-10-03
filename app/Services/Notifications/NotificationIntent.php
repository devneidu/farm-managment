<?php

namespace App\Services\Notifications;

/** What the system has decided to tell a user about (before preferences, deduplication and storage). */
final class NotificationIntent
{
    /**
     * @param  array{type: string, id: string|null, reference: string|null}|null  $subject  the thing it is about (traceability back to the source)
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $message,
        public readonly string $dedupeKey,
        public readonly ?array $subject = null,
        public readonly array $data = [],
    ) {}
}
