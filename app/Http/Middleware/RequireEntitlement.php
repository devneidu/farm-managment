<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Services\Subscription\EntitlementService;
use App\Support\Access\FarmContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Usage: `entitlement:advanced_reports` (a Feature key). Must run after farm.context.
 * Plan gate only; RBAC is enforced separately by farm.permission. Denies with
 * 403 feature_not_available | subscription_inactive.
 */
class RequireEntitlement
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        app(EntitlementService::class)->assertAllows(app(FarmContext::class)->farm, Feature::from($feature));

        return $next($request);
    }
}
