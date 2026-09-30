<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CropProjectDetail extends Model
{
    protected $primaryKey = 'production_cycle_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['initial_planting_units' => 'integer', 'baseline_measurement' => 'array', 'area_measurement' => 'array', 'area_normalized_quantity' => 'decimal:6', 'expected_germination_date' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $detail) {
            if ($detail->isDirty(['production_cycle_id', 'crop_type_id', 'crop_variety_id', 'planting_material_type_id', 'planting_unit_type_id', 'initial_planting_units', 'baseline_measurement'])) {
                throw new LogicException('Crop baseline is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Crop baseline cannot be deleted.'));
    }

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class, 'crop_variety_id');
    }

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'planting_material_type_id');
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'planting_unit_type_id');
    }
}
