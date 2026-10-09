<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LivestockBatchDetail extends Model
{
    protected $primaryKey = 'production_cycle_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['initial_population' => 'integer', 'baseline_measurement' => 'array', 'acquisition_price_per_animal' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Livestock baseline is immutable.'));
        static::deleting(fn () => throw new LogicException('Livestock baseline cannot be deleted.'));
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function breed(): BelongsTo
    {
        return $this->belongsTo(Breed::class);
    }

    public function productionPurpose(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'production_purpose_id');
    }

    public function growthStage(): BelongsTo
    {
        return $this->belongsTo(ReferenceValue::class, 'growth_stage_id');
    }

    public function supplierContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_contact_id');
    }
}
