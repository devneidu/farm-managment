<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class SaleItem extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['measurement' => 'array', 'head_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $line) {
            // The only permitted change: linking the population record the line created (set once, right after insert).
            if (array_diff(array_keys($line->getDirty()), ['operational_record_id', 'updated_at']) !== [] || $line->getOriginal('operational_record_id') !== null) {
                throw new LogicException('Sale lines are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Sale lines are immutable.'));
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
        return $this->hasOne(InventoryMovement::class, 'sale_item_id');
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(OperationalRecord::class, 'operational_record_id');
    }
}
