<?php

namespace App\Models;

use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ProductionCycle extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => CycleKind::class, 'status' => CycleStatus::class, 'start_date' => 'immutable_date', 'expected_end_date' => 'immutable_date', 'end_date' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cycle) {
            if ($cycle->isDirty(['farm_id', 'kind', 'reference', 'operation_type_id', 'start_date', 'created_by'])) {
                throw new LogicException('Cycle identity and starting baseline are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Close production cycles; never delete history.'));
    }

    public function scopeOfFarm(Builder $query, Farm $farm): Builder
    {
        return $query->where('farm_id', $farm->id);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(OperationType::class, 'operation_type_id');
    }

    public function productionArea(): BelongsTo
    {
        return $this->belongsTo(ProductionArea::class);
    }

    public function livestock(): HasOne
    {
        return $this->hasOne(LivestockBatchDetail::class);
    }

    public function crop(): HasOne
    {
        return $this->hasOne(CropProjectDetail::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PopulationMovement::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductionCycleEvent::class);
    }
}
