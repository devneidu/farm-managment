<?php

namespace App\Models;

use App\Enums\OfferStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A buyer's priced proposal on a negotiable listing, with a snapshot of the listing as it stood. Terms and snapshot are written once; only the
 * lifecycle columns change, and only through MarketplaceOfferService. `pending_slot` is 'P' while pending and NULL afterwards (unique key).
 */
class MarketplaceOffer extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class, 'attempt_no' => 'integer', 'listing_version' => 'integer',
            'expires_at' => 'datetime', 'responded_at' => 'datetime',
        ];
    }

    /** What clients see: a pending offer past its deadline is already expired, whether or not the sweep has persisted it yet. */
    public function effectiveStatus(): OfferStatus
    {
        return $this->status === OfferStatus::Pending && $this->expires_at->lte(now()) ? OfferStatus::Expired : $this->status;
    }

    public function isDue(): bool
    {
        return $this->status === OfferStatus::Pending && $this->expires_at->lte(now());
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

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceOfferEvent::class, 'offer_id')->orderBy('created_at')->orderBy('id');
    }

    /** The deal this accepted offer became (at most one). */
    public function deal(): HasOne
    {
        return $this->hasOne(MarketplaceDeal::class, 'offer_id');
    }
}
