<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Initial baseline and linked operational effects share one append-only population ledger. */
class PopulationMovement extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Population movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Population movements are append-only.'));
    }
}
