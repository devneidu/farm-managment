<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Models\Plan;
use App\Models\Subscription;

/**
 * The effective entitlements of one farm at one moment: which plan is honoured, which features are on,
 * and each limit. Built only by EntitlementService. Anything not explicitly granted is denied.
 */
final readonly class EntitlementSet
{
    /**
     * @param  array<string, bool>  $features  keyed by Feature value
     * @param  array<string, LimitValue>  $limits  keyed by Limit value
     */
    public function __construct(
        public ?Plan $plan,
        public ?Subscription $subscription,
        public bool $subscriptionInactive,
        private array $features,
        private array $limits,
    ) {}

    public function allows(Feature $feature): bool
    {
        return $this->features[$feature->value] ?? false;
    }

    public function limit(Limit $limit): LimitValue
    {
        return $this->limits[$limit->value] ?? LimitValue::none();
    }
}
