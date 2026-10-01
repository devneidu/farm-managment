<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** One medicine used in a health record: dose, the stock it consumed and its withdrawal window. Immutable. */
class HealthRecordMedicine extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['measurement' => 'array', 'dose' => 'array', 'withdrawal_ends_at' => 'immutable_datetime', 'withdrawal_days' => 'integer', 'line_no' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Health medicine lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Health medicine lines are immutable.'));
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(HealthRecord::class, 'health_record_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function movement(): HasOne
    {
        return $this->hasOne(InventoryMovement::class, 'health_record_medicine_id');
    }
}
