<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiHttpException;
use Closure;
use Illuminate\Http\Request;

class EnsureOnboarded
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->isOnboarded()) {
            throw new ApiHttpException(403, 'onboarding_required', 'Complete farm setup to continue.');
        }

        return $next($request);
    }
}
