<?php

namespace App\Services\Subscription;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Entitlement;
use App\Models\Farm;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Support\Entitlements\EntitlementException;
use App\Support\Entitlements\EntitlementSet;
use App\Support\Entitlements\LimitValue;

/**
 * THE place that answers "what does this farm's subscription allow?". Application code asks this
 * service (or uses the `entitlement:` middleware); it never compares plan names or ids.
 *
 * Resolution is deny-by-default:
 *  - the farm's subscription plan is honoured only while the subscription is current (active/past_due,
 *    paid period not lapsed) AND the plan is active;
 *  - otherwise the farm falls back to the default plan (Free) and `subscriptionInactive` is reported;
 *  - no subscription row => the default plan; no active default plan => nothing is allowed, limits are 0;
 *  - missing, malformed or unknown entitlement rows never grant anything.
 * Entitlements gate NEW actions only; nothing here deletes or hides existing data.
 * Results are not cached across calls, so a plan change is visible immediately.
 */
class EntitlementService
{
    public function __construct(private readonly UsageResolver $usage) {}

    public function for(Farm $farm): EntitlementSet
    {
        $subscription = Subscription::with('plan')->where('farm_id', $farm->id)->first();
        $default = null;
        $plan = null;
        $inactive = false;

        if ($subscription && $subscription->isCurrent() && $subscription->plan?->is_active) {
            $plan = $subscription->plan;
        } else {
            $default = $this->defaultPlan();
            $plan = $default;
            $inactive = $subscription !== null && $subscription->plan_id !== $default?->id;
        }

        [$features, $limits] = $plan ? $this->forPlan($plan) : [[], []];

        return new EntitlementSet($plan, $subscription, $inactive, $features, $limits);
    }

    public function allows(Farm $farm, Feature $feature): bool
    {
        return $this->for($farm)->allows($feature);
    }

    public function limit(Farm $farm, Limit $limit): LimitValue
    {
        return $this->for($farm)->limit($limit);
    }

    public function usage(Farm $farm, Limit $limit): int
    {
        return $this->usage->usage($farm, $limit);
    }

    /** Remaining capacity, never negative; null means unlimited. */
    public function remaining(Farm $farm, Limit $limit): ?int
    {
        return $this->limit($farm, $limit)->remaining($this->usage($farm, $limit));
    }

    /** @throws EntitlementException feature_not_available | subscription_inactive */
    public function assertAllows(Farm $farm, Feature $feature): void
    {
        $set = $this->for($farm);

        if ($set->allows($feature)) {
            return;
        }

        throw $set->subscriptionInactive
            ? EntitlementException::subscriptionInactive($feature->value)
            : EntitlementException::featureNotAvailable($feature);
    }

    /**
     * Fails when $additional more units would exceed the limit. Existing over-limit usage (after a downgrade)
     * is never removed; it only blocks growth. NOT concurrency-safe on its own: callers that consume capacity
     * must hold a lock on the farm row in the same transaction (see InvitationService::invite).
     *
     * @throws EntitlementException plan_limit_reached
     */
    public function assertCapacity(Farm $farm, Limit $limit, int $additional = 1): void
    {
        $value = $this->limit($farm, $limit);
        $usage = $this->usage($farm, $limit);

        if (! $value->allows($usage, $additional)) {
            throw EntitlementException::limitReached($limit, $value, $usage);
        }
    }

    public function defaultPlan(): ?Plan
    {
        return Plan::where('is_default', true)->where('is_active', true)->orderBy('sort_order')->first();
    }

    /**
     * The feature flags and limits configured on a plan (used for plan comparison and for resolution).
     * Unknown keys, wrong-typed rows and malformed values are ignored/denied.
     *
     * @return array{0: array<string, bool>, 1: array<string, LimitValue>}
     */
    public function forPlan(Plan $plan): array
    {
        $rows = PlanEntitlement::query()
            ->join('entitlements', 'entitlements.id', '=', 'plan_entitlements.entitlement_id')
            ->where('plan_entitlements.plan_id', $plan->id)
            ->get(['entitlements.key', 'entitlements.type', 'plan_entitlements.enabled', 'plan_entitlements.limit_value', 'plan_entitlements.is_unlimited']);

        $features = [];
        $limits = [];

        foreach ($rows as $row) {
            if ($row->type === Entitlement::TYPE_FEATURE && Feature::tryFrom($row->key)) {
                $features[$row->key] = $row->enabled === true;
            } elseif ($row->type === Entitlement::TYPE_LIMIT && Limit::tryFrom($row->key)) {
                $limits[$row->key] = match (true) {
                    $row->is_unlimited === true => LimitValue::unlimited(),
                    $row->limit_value !== null => LimitValue::of($row->limit_value),
                    default => LimitValue::none(),
                };
            }
        }

        return [$features, $limits];
    }
}
