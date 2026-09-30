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
        return ['initial_population' => 'integer', 'baseline_measurement' => 'array'];
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
}
