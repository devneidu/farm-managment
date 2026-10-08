<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One prepaid period of a paid seller plan for one shop. Periods of a shop never overlap (a renewal starts when the current one ends). */
class MarketplaceShopSubscription extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['interval_days' => 'integer', 'listing_limit' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** Running right now (evaluated when read; nothing flips at expiry). */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSellerPlan::class, 'plan_id');
    }
}
