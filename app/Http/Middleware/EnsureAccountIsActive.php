<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionAuthenticator;
use App\Support\Api\ApiHttpException;
use Closure;
use Illuminate\Http\Request;

class EnsureAccountIsActive
{
    public function __construct(private readonly SessionAuthenticator $sessions) {}

    public function handle(Request $request, Closure $next)
    {
        // A deleted (anonymised) account is gone: any lingering session or token is treated as signed out.
        if ($request->user()?->isAnonymised()) {
            $this->sessions->logout($request);

            throw new ApiHttpException(401, 'unauthenticated', 'Unauthenticated.');
        }

        if ($request->user()?->isSuspended()) {
            $this->sessions->logout($request);

            throw new ApiHttpException(403, 'account_suspended', 'This account is suspended.');
        }

        return $next($request);
    }
}
