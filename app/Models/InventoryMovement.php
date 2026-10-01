<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** The append-only stock ledger. Corrections are new (reversal/adjustment) rows, never edits. */
class InventoryMovement extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'measurement' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory movements are append-only; reverse or adjust instead.'));
        static::deleting(fn () => throw new LogicException('Inventory movements are append-only.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }
}
