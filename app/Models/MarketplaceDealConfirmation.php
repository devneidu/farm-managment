<?php

namespace App\Models;

use App\Enums\ConfirmationStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A seller's confirmation of a fixed-price purchase intent, awaiting the buyer. Not an agreement and not a deal; it carries no contact detail.
 * `open_slot` is 'O' while awaiting the buyer and NULL afterwards (unique key with the intent).
 */
class MarketplaceDealConfirmation extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => ConfirmationStatus::class, 'listing_version' => 'integer', 'expires_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    /** What clients see: an unanswered confirmation past its deadline is already lapsed, whether or not the sweep has persisted it yet. */
    public function effectiveStatus(): ConfirmationStatus
    {
        return $this->isDue() ? ConfirmationStatus::Lapsed : $this->status;
    }

    public function isDue(): bool
    {
        return $this->status === ConfirmationStatus::AwaitingBuyer && $this->expires_at->lte(now());
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(MarketplacePurchaseIntent::class, 'intent_id');
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

    public function deal(): HasOne
    {
        return $this->hasOne(MarketplaceDeal::class, 'confirmation_id');
    }
}
