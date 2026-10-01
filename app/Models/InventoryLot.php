<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/** A traceable receipt/batch of one item. Identity (code, expiry) is immutable so history stays explainable. */
class InventoryLot extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory lots are immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory lots are immutable.'));
    }

    public static function normalizeCode(string $code): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $code)));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
