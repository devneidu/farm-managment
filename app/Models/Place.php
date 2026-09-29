<?php

namespace App\Models;

use App\Enums\PlaceKind;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/** Shared physical-place fields. All application writes go through PlaceService's per-farm lock. */
abstract class Place extends Model
{
    use HasUuidV7;

    protected $fillable = ['name', 'type', 'is_active'];

    abstract public function kind(): PlaceKind;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $place) {
            $place->name = static::cleanName($place->name);
            $place->normalized_name = Str::lower($place->name);
        });
        static::deleting(fn () => throw new LogicException('Places must be deactivated, not deleted.'));
    }

    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public function scopeOfFarm(Builder $query, Farm $farm): Builder
    {
        return $query->where($this->qualifyColumn('farm_id'), $farm->id);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, $this->kind()->parentColumn());
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }
}
