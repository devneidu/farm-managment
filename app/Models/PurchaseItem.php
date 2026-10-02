<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PurchaseItem extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['measurement' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Purchase lines are immutable.'));
        static::deleting(fn () => throw new LogicException('Purchase lines are immutable.'));
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
        return $this->hasOne(InventoryMovement::class, 'purchase_item_id');
    }
}
