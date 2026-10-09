<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A product listing in a seller shop. Lifecycle columns, `version`, ownership and the private farm link are never mass assignable from request
 * input: services set them. `farm_id` / `inventory_item_id` are PRIVATE and appear in no public resource.
 */
class MarketplaceListing extends Model
{
    use HasUuidV7;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class, 'negotiable' => 'boolean', 'delivery_coverage' => 'array', 'version' => 'integer',
            'quantity_updated_at' => 'datetime', 'published_at' => 'datetime', 'paused_at' => 'datetime', 'archived_at' => 'datetime',
            'restricted_at' => 'datetime', 'inventory_synced_at' => 'datetime',
        ];
    }

    /**
     * The single definition of "visible to the public": published AND the shop is active (not draft, pending, rejected, suspended or closed).
     * Evaluated at read time, so suspending or closing a shop hides its listings at once with no per-listing write.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ListingStatus::Published->value)
            ->whereHas('shop', fn (Builder $shop) => $shop->public());
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'shop_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function packageBasisUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'package_basis_unit_id');
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class);
    }

    public function catalogImage(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCatalogImage::class, 'catalog_image_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(MarketplaceListingImage::class, 'listing_id')->orderBy('position')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceListingEvent::class, 'listing_id')->orderBy('created_at')->orderBy('id');
    }
}
