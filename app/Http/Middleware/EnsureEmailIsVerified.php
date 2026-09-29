<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiHttpException;
use Closure;
use Illuminate\Http\Request;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            throw new ApiHttpException(403, 'email_verification_required', 'Verify your email address to continue.');
        }

        return $next($request);
    }
}
