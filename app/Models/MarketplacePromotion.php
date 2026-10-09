<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A paid promotion of one listing for a fixed window. Expiry is read from `expires_at`; there is no job that must run for it to take effect. */
class MarketplacePromotion extends Model
{
    use HasUuidV7;

    public const ACTIVE = 'active';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['duration_days' => 'integer', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** Inside its paid window and not cancelled. Visibility rules (public listing, active shop) are applied by the caller. */
    public function scopeRunning(Builder $q): Builder
    {
        return $q->where($q->qualifyColumn('status'), self::ACTIVE)->where($q->qualifyColumn('starts_at'), '<=', now())->where($q->qualifyColumn('expires_at'), '>', now());
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id')->withTrashed();
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'shop_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(MarketplaceServicePayment::class, 'payment_id');
    }
}
