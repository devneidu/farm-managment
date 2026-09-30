<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Cycle lifecycle history for the activity endpoint; not an operational record engine. */
class ProductionCycleEvent extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cycle events are append-only.'));
        static::deleting(fn () => throw new LogicException('Cycle events are append-only.'));
    }
}
