<?php

namespace App\Models;

use App\Enums\ShopStatus;
use App\Enums\ShopVerificationStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seller shop. Owned by users (members), optionally linked to one farm at creation. Only status/verification services mutate the lifecycle
 * columns; they are not mass assignable from request input.
 */
class MarketplaceShop extends Model
{
    use HasUuidV7;

    public const SELLER_TYPES = ['individual', 'business', 'farm'];

    public const CATEGORIES = ['livestock', 'crops', 'eggs_dairy', 'feed_inputs', 'equipment', 'processed_goods', 'services'];

    public const CONTACT_METHODS = ['in_app', 'phone', 'whatsapp', 'email'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'status' => ShopStatus::class,
            'verification_status' => ShopVerificationStatus::class,
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'suspended_at' => 'datetime', 'closed_at' => 'datetime',
            'verification_requested_at' => 'datetime', 'verified_at' => 'datetime',
        ];
    }

    /** The single definition of "visible to the public". */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), ShopStatus::Active->value);
    }

    public function members(): HasMany
    {
        return $this->hasMany(MarketplaceShopMember::class, 'shop_id');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    /** @return list<string> the channels the seller has configured, never the values */
    public function contactMethods(): array
    {
        return array_values(array_filter([
            'in_app',
            $this->contact_phone ? 'phone' : null,
            $this->contact_whatsapp ? 'whatsapp' : null,
            $this->contact_email ? 'email' : null,
        ]));
    }
}
