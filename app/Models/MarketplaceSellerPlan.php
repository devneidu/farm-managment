<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A marketplace seller plan (shop-scoped, separate from the farm-scoped Plan). `listing_limit` = published listings allowed at once; NULL = unlimited. */
class MarketplaceSellerPlan extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['listing_limit' => 'integer', 'is_free' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(MarketplaceSellerPlanPrice::class, 'plan_id')->orderBy('interval_days');
    }
}
