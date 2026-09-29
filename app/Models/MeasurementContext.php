<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A farm-defined thing that packages are measured against ("Feed Grower Mash") until real domain entities such as
 * inventory items exist. Its `id` is the durable identity package conversions and snapshots refer to; `name` is display
 * only and may change. Always farm-scoped: it is never visible to, or usable by, another farm.
 */
class MeasurementContext extends Model
{
    use HasUuidV7;

    protected $fillable = ['farm_id', 'name', 'normalized_name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $context) => $context->normalized_name = static::normalizeName($context->name));
    }

    /** Trim, collapse whitespace, case-fold: the duplicate-detection key. */
    public static function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    public function scopeOfFarm(Builder $query, Farm $farm): Builder
    {
        return $query->where($this->qualifyColumn('farm_id'), $farm->id);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
