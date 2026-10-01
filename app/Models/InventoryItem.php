<?php

namespace App\Models;

use App\Enums\InventoryCategory;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

/** A stockable thing. It owns NO quantity: stock is derived from its inventory_movements. */
class InventoryItem extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['category' => InventoryCategory::class, 'tracks_lots' => 'boolean', 'tracks_expiry' => 'boolean', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $item) => $item->normalized_name = static::normalizeName($item->name));
        static::updating(function (self $item) {
            if ($item->isDirty(['farm_id', 'created_by'])) {
                throw new LogicException('Inventory item ownership is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Deactivate inventory items; never delete stock history.'));
    }

    public static function normalizeName(string $name): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    public function scopeOfFarm(Builder $query, Farm $farm): Builder
    {
        return $query->where($this->qualifyColumn('farm_id'), $farm->id);
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'stock_unit_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }

    public function dimension(): string
    {
        return $this->stockUnit->dimension->code;
    }
}
