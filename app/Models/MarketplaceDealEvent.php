<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of a deal (who did what, from which state to which). Never updated or deleted. */
class MarketplaceDealEvent extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Deal history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Deal history is append-only.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
