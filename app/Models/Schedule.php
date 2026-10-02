<?php

namespace App\Models;

use App\Enums\Recurrence;
use App\Enums\TaskCategory;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** A recurrence rule. It only ever generates tasks (work that should happen). */
class Schedule extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'recurrence' => Recurrence::class, 'category' => TaskCategory::class, 'requires_evidence' => 'boolean', 'weekdays' => 'array', 'reminder_offsets' => 'array',
            'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('End schedules; never delete history.'));
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
