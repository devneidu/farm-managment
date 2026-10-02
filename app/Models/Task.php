<?php

namespace App\Models;

use App\Enums\DueState;
use App\Enums\TaskCategory;
use App\Enums\TaskStatus;
use App\Models\Concerns\HasUuidV7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Work that SHOULD happen. Completing it never creates an operational record; it can only link to one that already exists. */
class Task extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class, 'category' => TaskCategory::class, 'requires_evidence' => 'boolean', 'reminder_offsets' => 'array',
            'due_date' => 'immutable_date', 'occurrence_date' => 'immutable_date', 'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $task) {
            if ($task->isDirty(['farm_id', 'reference', 'schedule_id', 'occurrence_date', 'production_cycle_id', 'breeding_project_id', 'created_by'])) {
                throw new LogicException('Task identity, schedule occurrence and context are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cancel tasks; never delete history.'));
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', TaskStatus::Open->value);
    }

    public function dueState(CarbonImmutable $now, string $today): DueState
    {
        return DueState::derive($this->status, $this->due_at, $this->due_date->toDateString(), $now, $today);
    }
}
