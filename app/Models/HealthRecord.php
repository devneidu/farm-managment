<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** A real health event. Append-only: corrections are a reversal row plus a linked replacement. */
class HealthRecord extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'recorded_at' => 'immutable_datetime', 'follow_up_on' => 'immutable_date', 'animals_affected' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Health records are append-only; reverse and replace instead.'));
        static::deleting(fn () => throw new LogicException('Health records are append-only.'));
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(HealthRecordMedicine::class)->orderBy('line_no');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_record_id');
    }

    public function isReversed(): bool
    {
        return $this->reversal !== null;
    }
}
