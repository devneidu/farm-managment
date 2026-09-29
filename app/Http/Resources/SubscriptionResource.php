<?php

namespace App\Http\Resources;

use App\Models\Farm;
use App\Models\Subscription;
use App\Services\Subscription\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The farm's subscription. `plan` is the plan the farm subscribed to; `effective_plan` is the plan whose
 * entitlements are actually honoured right now (they differ when the subscription lapsed or the plan was
 * retired, in which case `subscription_inactive` is true and the default plan applies).
 *
 * @property Subscription $resource
 */
class SubscriptionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string,
     *     status: 'active'|'past_due'|'cancelled'|'expired',
     *     billing_interval: 'monthly'|'annual'|null,
     *     starts_at: string|null,
     *     current_period_start: string|null,
     *     current_period_end: string|null,
     *     cancel_at_period_end: bool,
     *     cancelled_at: string|null,
     *     ends_at: string|null,
     *     subscription_inactive: bool,
     *     plan: PlanResource,
     *     effective_plan: array{id: string, slug: string, name: string}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $sub = $this->resource->loadMissing('plan.prices');
        $set = app(EntitlementService::class)->for($sub->farm ?? Farm::findOrFail($sub->farm_id));

        return [
            'id' => $sub->id,
            'status' => $sub->status->value,
            'billing_interval' => $sub->billing_interval?->value,
            'starts_at' => $sub->starts_at?->toIso8601String(),
            'current_period_start' => $sub->current_period_start?->toIso8601String(),
            'current_period_end' => $sub->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => $sub->cancel_at_period_end,
            'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
            'ends_at' => $sub->ends_at?->toIso8601String(),
            'subscription_inactive' => $set->subscriptionInactive,
            'plan' => (new PlanResource($sub->plan))->resolve($request),
            'effective_plan' => $set->plan ? ['id' => $set->plan->id, 'slug' => $set->plan->slug, 'name' => $set->plan->name] : null,
        ];
    }
}
