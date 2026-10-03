<?php

namespace App\Services\Notifications;

use App\Models\FarmMembership;
use App\Models\FarmNotification;
use App\Notifications\FarmAlertNotification;
use App\Support\Access\NotificationPreferences;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RECORD step: turns decided intents into inbox records for ONE member, honouring that member's preferences and never announcing the same
 * condition twice ((user, farm, dedupe key) is unique). The source of an intent is never modified.
 */
class NotificationService
{
    /**
     * @param  list<NotificationIntent>  $intents
     * @return int how many NEW notifications were created
     */
    public function deliver(FarmMembership $membership, array $intents): int
    {
        $stored = $membership->notification_preferences;
        $channels = NotificationPreferences::resolve($stored)['channels'];
        if (! $channels['in_app'] && ! $channels['email']) {
            return 0;
        }

        $created = 0;
        foreach ($intents as $intent) {
            if (! NotificationPreferences::typeEnabled($stored, $intent->type)) {
                continue;
            }
            $created += DB::transaction(function () use ($membership, $intent, $channels) {
                $record = $this->store($membership, $intent, $channels['in_app']);
                if ($record === null) {
                    return 0;
                }
                if ($channels['email'] && $membership->user?->email) {
                    $record->forceFill(['email_status' => 'queued'])->save();
                    $membership->user->notify(new FarmAlertNotification($record->id));
                }

                return 1;
            });
        }

        return $created;
    }

    /** A single event notification (for example "your export is ready") for one member. */
    public function notify(FarmMembership $membership, NotificationIntent $intent): bool
    {
        return $this->deliver($membership, [$intent]) > 0;
    }

    private function store(FarmMembership $membership, NotificationIntent $intent, bool $inApp): ?FarmNotification
    {
        $exists = FarmNotification::where('user_id', $membership->user_id)->where('farm_id', $membership->farm_id)->where('dedupe_key', $intent->dedupeKey)->exists();
        if ($exists) {
            return null;
        }
        try {
            return FarmNotification::create([
                'farm_id' => $membership->farm_id, 'user_id' => $membership->user_id, 'type' => $intent->type, 'severity' => $intent->severity,
                'title' => Str::limit($intent->title, 160, ''), 'message' => Str::limit($intent->message, 500, ''),
                'subject_type' => $intent->subject['type'] ?? null, 'subject_id' => $intent->subject['id'] ?? null,
                'subject_reference' => isset($intent->subject['reference']) ? Str::limit((string) $intent->subject['reference'], 60, '') : null,
                'data' => $intent->data ?: null, 'dedupe_key' => $intent->dedupeKey, 'in_app' => $inApp,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // announced concurrently by another worker
        }
    }
}
