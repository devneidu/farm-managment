<?php

namespace App\Models;

use App\Enums\ConversionContextType;
use App\Models\Concerns\HasUuidV7;
use App\Support\Measurement\ConversionContext;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "In context (context_type, context_id), 1 package = N target units" for one farm. The context is identified by id only;
 * its display name is read from the entity it points at (crop type / measurement context), never stored here.
 */
class PackageConversion extends Model
{
    use HasUuidV7;

    /** Relations that resolve the context label; eager load them when listing. */
    public const CONTEXT_RELATIONS = ['measurementContext', 'cropType'];

    protected $fillable = [
        'farm_id', 'context_type', 'context_id', 'package_unit_id', 'target_unit_id',
        'quantity_per_package', 'version', 'is_active',
    ];

    protected function casts(): array
    {
        return ['context_type' => ConversionContextType::class, 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function packageUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'package_unit_id');
    }

    public function targetUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'target_unit_id');
    }

    /** Only meaningful when context_type = custom. */
    public function measurementContext(): BelongsTo
    {
        return $this->belongsTo(MeasurementContext::class, 'context_id');
    }

    /** Only meaningful when context_type = crop_type. */
    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class, 'context_id');
    }

    protected function contextLabel(): Attribute
    {
        return Attribute::get(fn () => match ($this->context_type) {
            ConversionContextType::Custom => $this->measurementContext?->name,
            ConversionContextType::CropType => $this->cropType?->name,
        } ?? '');
    }

    public function conversionContext(): ConversionContext
    {
        return new ConversionContext($this->context_type, $this->context_id, $this->context_label);
    }
}
