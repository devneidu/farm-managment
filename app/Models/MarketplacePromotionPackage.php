<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** A fixed-price, fixed-duration promotion offer configured by the platform. */
class MarketplacePromotionPackage extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['duration_days' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
