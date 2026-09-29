<?php

namespace App\Http\Middleware;

use App\Models\FarmMembership;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Closure;
use Illuminate\Http\Request;

/**
 * Resolves the farm the request operates on from the caller's own ACTIVE memberships.
 * Default: the oldest active membership. Optional `X-Farm-Id` header selects another farm the
 * user actively belongs to (future multi-farm); any other value is a 403, never a leak.
 */
class ResolveFarmContext
{
    public function handle(Request $request, Closure $next)
    {
        $memberships = $request->user()->memberships()->active()->with('farm');

        $requested = $request->header('X-Farm-Id');

        if ($requested !== null && $requested !== '') {
            $membership = $memberships->where('farm_id', $requested)->first();

            if (! $membership) {
                throw new ApiHttpException(403, 'farm_access_denied', 'You do not have access to this farm.');
            }
        } else {
            /** @var FarmMembership|null $membership */
            $membership = $memberships->orderBy('created_at')->orderBy('id')->first();

            if (! $membership) {
                throw new ApiHttpException(403, 'no_active_farm', 'You do not belong to an active farm.');
            }
        }

        app()->instance(FarmContext::class, new FarmContext($membership->farm, $membership));

        return $next($request);
    }
}
