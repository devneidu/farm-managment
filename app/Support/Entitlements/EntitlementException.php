<?php

namespace App\Support\Entitlements;

use App\Enums\Feature;
use App\Enums\Limit;
use App\Support\Api\ApiHttpException;

/**
 * Plan-based denials. Distinct from RBAC's `forbidden`: the user may hold the permission,
 * but the farm's subscription does not allow the feature or capacity.
 */
class EntitlementException extends ApiHttpException
{
    public static function featureNotAvailable(Feature $feature): self
    {
        return new self(403, 'feature_not_available', "{$feature->label()} is not included in your farm's current plan.", details: [
            'entitlement_key' => $feature->value,
        ]);
    }

    public static function subscriptionInactive(string $key): self
    {
        return new self(403, 'subscription_inactive', "This farm's subscription is not active. Renew it to use this feature.", details: [
            'entitlement_key' => $key,
        ]);
    }

    public static function limitReached(Limit $limit, LimitValue $value, int $usage): self
    {
        return new self(409, 'plan_limit_reached', "Your farm's plan allows {$value->value} ".strtolower($limit->label()).' and the limit has been reached.', details: [
            'entitlement_key' => $limit->value,
            'limit' => $value->value,
            'usage' => $usage,
            'remaining' => $value->remaining($usage),
        ]);
    }
}
