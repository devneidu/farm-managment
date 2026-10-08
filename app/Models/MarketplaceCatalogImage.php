<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A reusable, ILLUSTRATIVE product image. Offered and served only once an asset has been seeded (`asset_path` not null). */
class MarketplaceCatalogImage extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_kind_fallback' => 'boolean', 'is_illustrative' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Active AND backed by an asset: the only rows that may reach a client. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('asset_path');
    }

    public function hasAsset(): bool
    {
        return $this->asset_path !== null;
    }
}
