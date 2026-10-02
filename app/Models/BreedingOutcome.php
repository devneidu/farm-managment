<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** An actual outcome (or its reversal). Append-only; the population effect lives in the linked Phase 8 operational record. */
class BreedingOutcome extends Model
{
    use HasUuidV7;

    public const OUTCOME = 'outcome';

    public const REVERSAL = 'reversal';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['live_count' => 'integer', 'loss_count' => 'integer', 'outcome_date' => 'immutable_date', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Breeding outcomes are append-only; reverse and replace instead.'));
        static::deleting(fn () => throw new LogicException('Breeding outcomes are append-only.'));
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_outcome_id');
    }
}
