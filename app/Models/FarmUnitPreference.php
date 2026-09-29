<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmUnitPreference extends Model
{
    use HasUuidV7;

    protected $fillable = ['farm_id', 'dimension_id', 'unit_id'];

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(MeasurementDimension::class, 'dimension_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
