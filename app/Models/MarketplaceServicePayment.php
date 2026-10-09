<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money collected by Farmvest for its own marketplace services. Never a buyer-seller payment. Status and `settled_at` change only through
 * ServicePaymentSettler (settled_at is written once, in the transaction that grants the benefit).
 */
class MarketplaceServicePayment extends Model
{
    use HasUuidV7;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const ABANDONED = 'abandoned';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['interval_days' => 'integer', 'verified_at' => 'datetime', 'paid_at' => 'datetime', 'settled_at' => 'datetime'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'shop_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
