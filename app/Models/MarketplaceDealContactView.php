<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Append-only record that someone read a party's private contact for a deal: who, which side, which field names (never values). */
class MarketplaceDealContactView extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Contact-access records are append-only.'));
        static::deleting(fn () => throw new \LogicException('Contact-access records are append-only.'));
    }

    protected function casts(): array
    {
        return ['fields' => 'array', 'created_at' => 'datetime'];
    }
}
