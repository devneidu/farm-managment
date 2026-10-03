<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-level commercial plan. Plans are data: names, prices, features and limits are editable
 * (later by Platform Admin) and never referenced by name in application logic.
 */
class Plan extends Model
{
    use HasUuidV7;

    protected $fillable = ['slug', 'name', 'description', 'currency', 'is_active', 'is_public', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Plans shown in the public catalogue, in a deterministic order. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->orderBy('sort_order')->orderBy('slug');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function planEntitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }
}
