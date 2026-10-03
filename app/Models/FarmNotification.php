<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One user's inbox record for one farm. The thing it announces (an insight, a task reminder, an export) is not touched: read state belongs
 * here. (user, farm, dedupe_key) is unique, so a condition is announced once per period no matter how often it is evaluated.
 */
class FarmNotification extends Model
{
    use HasUuidV7;

    protected $table = 'notifications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'in_app' => 'boolean', 'read_at' => 'immutable_datetime', 'emailed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $n) {
            if ($n->isDirty(['farm_id', 'user_id', 'type', 'severity', 'title', 'message', 'subject_type', 'subject_id', 'subject_reference', 'data', 'dedupe_key'])) {
                throw new LogicException('A notification is immutable; only its read and delivery state change.');
            }
        });
        static::deleting(fn () => throw new LogicException('Notifications are kept; mark them read instead.'));
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /** The inbox of one user on one farm (the only way notifications are ever listed). */
    public function scopeInboxOf(Builder $query, string $farmId, string $userId): Builder
    {
        return $query->where('farm_id', $farmId)->where('user_id', $userId)->where('in_app', true);
    }
}
