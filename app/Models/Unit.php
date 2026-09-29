<?php

namespace App\Models;

use App\Enums\ConversionStrategy;
use App\Models\Concerns\HasUuidV7;
use App\Support\Measurement\Decimal;
use App\Support\Measurement\UnitSpec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Unit extends Model
{
    use HasUuidV7;

    /** Attributes that define what a system unit MEANS. They can never change after creation. */
    private const PROTECTED = ['code', 'dimension_id', 'family', 'is_canonical', 'conversion_strategy', 'to_canonical_factor', 'integer_only'];

    protected $fillable = [
        'dimension_id', 'code', 'name', 'symbol', 'family', 'is_canonical', 'conversion_strategy', 'to_canonical_factor',
        'decimal_places', 'integer_only', 'is_system', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_canonical' => 'boolean', 'integer_only' => 'boolean', 'is_system' => 'boolean', 'is_active' => 'boolean',
            'conversion_strategy' => ConversionStrategy::class, 'decimal_places' => 'integer', 'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Standard units are protected: nobody (a farm, a future admin screen) can redefine kg, L or hectare.
        static::updating(function (self $unit) {
            if ($unit->is_system && $unit->isDirty(self::PROTECTED)) {
                throw new LogicException("System unit [{$unit->getOriginal('code')}] cannot be redefined.");
            }
        });
        static::deleting(function (self $unit) {
            if ($unit->is_system) {
                throw new LogicException("System unit [{$unit->code}] cannot be deleted.");
            }
        });
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(MeasurementDimension::class, 'dimension_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    /** Immutable description used by the converter and embedded in conversion snapshots. */
    public function spec(): UnitSpec
    {
        $this->loadMissing('dimension');

        return new UnitSpec(
            code: $this->code,
            dimension: $this->dimension->code,
            family: $this->family,
            strategy: $this->conversion_strategy,
            factor: $this->to_canonical_factor === null ? null : Decimal::trim((string) $this->to_canonical_factor),
            integerOnly: $this->integer_only,
        );
    }
}
