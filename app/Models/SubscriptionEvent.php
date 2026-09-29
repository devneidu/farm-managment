<?php

namespace App\Models;

use App\Enums\SubscriptionEventType;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of subscription lifecycle changes (audit/support groundwork). */
class SubscriptionEvent extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SubscriptionEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
