<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A record type made by a farm (farm_id set, source `farm`) or curated by the platform for every farm (farm_id NULL, source `public`).
 * `fields` is the bounded field schema (see CustomRecordSchema); records keep the schema `version` they were captured with.
 */
class CustomRecordType extends Model
{
    use HasUuidV7;

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const FARM = 'farm';

    public const PUBLIC = 'public';

    protected $fillable = ['farm_id', 'name', 'description', 'applies_to', 'species_id', 'crop_type_id', 'fields', 'version', 'status', 'source', 'copied_from_id', 'created_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $m) => $m->normalized_name = self::normalizeName($m->name));
    }

    /** Trim, collapse whitespace, case-fold: the duplicate-detection key. */
    public static function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    /** Public types plus this farm's own - never another farm's. */
    public function scopeVisibleTo(Builder $query, string $farmId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('farm_id')->orWhere('farm_id', $farmId));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function isPublic(): bool
    {
        return $this->farm_id === null;
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(OperationalRecord::class);
    }
}
