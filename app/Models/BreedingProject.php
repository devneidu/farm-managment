<?php

namespace App\Models;

use App\Enums\BreedingStatus;
use App\Enums\BreedingWorkflow;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** One reproductive attempt. History is kept: projects are cancelled or completed, never deleted. */
class BreedingProject extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'workflow' => BreedingWorkflow::class, 'status' => BreedingStatus::class, 'reference_snapshot' => 'array',
            'start_date' => 'immutable_date', 'expected_date' => 'immutable_date', 'expected_from' => 'immutable_date', 'expected_to' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime', 'eggs_set' => 'integer', 'females_bred' => 'integer', 'expected_offspring' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $project) {
            if ($project->isDirty(['farm_id', 'production_cycle_id', 'reference', 'workflow', 'reference_snapshot', 'created_by'])) {
                throw new LogicException('Breeding project identity, workflow and biological reference snapshot are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cancel breeding projects; never delete history.'));
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ProductionCycle::class, 'production_cycle_id');
    }

    public function parents(): HasMany
    {
        return $this->hasMany(BreedingParent::class)->orderBy('role')->orderBy('id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(BreedingCheck::class)->orderBy('checked_on')->orderBy('id');
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(BreedingOutcome::class)->orderBy('recorded_at')->orderBy('id');
    }
}
