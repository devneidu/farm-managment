<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class MarketplaceSellerPlanPrice extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['interval_days' => 'integer', 'is_active' => 'boolean'];
    }
}
