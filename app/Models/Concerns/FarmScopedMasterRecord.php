<?php

namespace App\Models\Concerns;

use App\Models\Farm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A master record that is either a SYSTEM default (farm_id NULL) or one farm's CUSTOM addition (farm_id set).
 * This trait is the one place the "system + my farm, never another farm" rule is written.
 * Using models declare `parentColumn()` (species_id / crop_type_id).
 */
trait FarmScopedMasterRecord
{
    abstract public static function parentColumn(): string;

    public static function bootFarmScopedMasterRecord(): void
    {
        static::saving(function ($model) {
            $model->normalized_name = static::normalizeName($model->name);
        });
    }

    /** Trim, collapse whitespace, case-fold: the duplicate-detection key. */
    public static function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    /** System defaults plus this farm's own custom rows - never another farm's. */
    public function scopeVisibleTo(Builder $query, Farm $farm): Builder
    {
        return $query->where(function (Builder $q) use ($farm) {
            $q->whereNull($this->qualifyColumn('farm_id'))->orWhere($this->qualifyColumn('farm_id'), $farm->id);
        });
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('farm_id'));
    }

    public function scopeCustomOf(Builder $query, Farm $farm): Builder
    {
        return $query->where($this->qualifyColumn('farm_id'), $farm->id);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_active'), true);
    }

    public function isSystem(): bool
    {
        return $this->farm_id === null;
    }

    /** 'system' | 'farm' */
    public function source(): string
    {
        return $this->isSystem() ? 'system' : 'farm';
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
