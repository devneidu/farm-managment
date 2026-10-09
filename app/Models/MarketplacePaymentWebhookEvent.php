<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** A stored payment-provider delivery. (provider, dedupe_key) is unique, so a redelivery is recognised and never activates anything twice. */
class MarketplacePaymentWebhookEvent extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
