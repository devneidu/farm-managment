<?php

namespace App\Models;

use App\Enums\DealStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The commercial and fulfilment terms two parties finalised, frozen at creation. Not an order, invoice, payment, reservation or sale. Terms and
 * snapshot are written once; only the lifecycle columns change, and only through MarketplaceDealLifecycle.
 */
class MarketplaceDeal extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DealStatus::class, 'listing_version' => 'integer', 'delivery_coverage' => 'array',
            'buyer_completed_at' => 'datetime', 'seller_completed_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
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

    public function offer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOffer::class, 'offer_id');
    }

    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(MarketplaceDealConfirmation::class, 'confirmation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceDealEvent::class, 'deal_id')->orderBy('created_at')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MarketplaceDealReport::class, 'deal_id')->orderBy('created_at')->orderBy('id');
    }
}
