<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of an offer (who did what, from which state to which). Never updated or deleted. */
class MarketplaceOfferEvent extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Offer history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Offer history is append-only.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
