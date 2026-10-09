<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Recorded buyer interest at a listing's listed unit price. Not an acceptance, an order, a payment or a deal. */
class MarketplacePurchaseIntent extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['listing_version' => 'integer', 'converted_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id')->withTrashed();
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'shop_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** The seller's confirmations of this intent, newest first. */
    public function confirmations(): HasMany
    {
        return $this->hasMany(MarketplaceDealConfirmation::class, 'intent_id')->orderByDesc('created_at')->orderByDesc('id');
    }
}
