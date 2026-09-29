<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The farm's single current subscription. Belongs to the FARM (users act through memberships). */
class Subscription extends Model
{
    use HasUuidV7;

    // Written only by SubscriptionService.
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_interval' => BillingInterval::class,
            'starts_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->orderBy('occurred_at');
    }

    /** True when the status may grant access and the paid period (if any) has not lapsed. */
    public function isCurrent(): bool
    {
        return $this->status->grantsAccess()
            && ($this->current_period_end === null || $this->current_period_end->isFuture());
    }
}
