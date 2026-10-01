<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class OperationalRecord extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'measurement' => 'array', 'population_delta' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Operational records are append-only; reverse and replace instead.'));
        static::deleting(fn () => throw new LogicException('Operational records are append-only.'));
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_record_id');
    }

    /** The stock effect of this record (feed use or its reversal), if it was linked to inventory. */
    public function inventoryMovement(): HasOne
    {
        return $this->hasOne(InventoryMovement::class, 'operational_record_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RecordAttachment::class);
    }
}
