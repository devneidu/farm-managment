<?php

namespace App\Support\Api\Docs;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Documents errors that are raised by middleware (not visible in controller code):
 * 429 for throttled routes, 419 for CSRF on writes, 403 for the verification/onboarding/farm-permission gates,
 * 404 for routes with path parameters.
 */
class AuthFlowResponsesExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        $middleware = collect($routeInfo->route->gatherMiddleware())->filter(fn ($m) => is_string($m));
        $documented = collect($operation->responses)->map(fn ($r) => (string) ($r->code ?? ''))->all();

        $add = function (int $status, string $description, array $codes) use ($operation, &$documented) {
            if (! in_array((string) $status, $documented, true)) {
                $operation->addResponse(ApiErrorResponse::make($status, $description, $codes));
                $documented[] = (string) $status;
            }
        };

        $forbidden = [];

        if ($middleware->contains('account.active') || $middleware->contains('app.access')) {
            $forbidden[] = 'account_suspended';
        }
        if ($middleware->contains('email.verified') || $middleware->contains('app.access')) {
            $forbidden[] = 'email_verification_required';
        }
        if ($middleware->contains('onboarded') || $middleware->contains('app.access')) {
            $forbidden[] = 'onboarding_required';
        }

        $permission = $middleware->first(fn ($m) => Str::startsWith($m, 'farm.permission:'));

        if ($middleware->contains('farm.context')) {
            $forbidden[] = 'no_active_farm';
            $forbidden[] = 'farm_access_denied';
        }

        if ($permission) {
            $forbidden[] = 'forbidden';
            $name = Str::after($permission, 'farm.permission:');
            $operation->description(trim(($operation->description ?? '')."

**Requires farm permission:** `{$name}`."));
        }

        if ($forbidden) {
            $add(403, 'Forbidden: account state does not allow this request', $forbidden);
        }

        if ($routeInfo->route->parameterNames() !== []) {
            $add(404, 'Resource not found (or it belongs to another farm)', ['not_found']);
        }

        if ($middleware->contains(fn ($m) => Str::startsWith($m, 'throttle:'))) {
            $add(429, 'Rate limited (see Retry-After header)', ['too_many_requests']);
        }

        if (in_array(strtoupper($operation->method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $add(419, 'CSRF token missing or expired: call GET /auth/csrf-cookie and retry', ['session_expired']);
        }
    }
}
