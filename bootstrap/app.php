<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\RequireEntitlement;
use App\Http\Middleware\RequireFarmPermission;
use App\Http\Middleware\RequirePlatformAdmin;
use App\Http\Middleware\ResolveFarmContext;
use App\Http\Middleware\SetRequestLocale;
use App\Support\Api\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api/v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(AssignRequestId::class);

        // Behind a load balancer / Cloudflare the real client IP (rate limits are keyed by it) comes from X-Forwarded-For, which is only
        // honoured from trusted proxies. Unset (default) = trust none, i.e. unchanged behaviour. TRUSTED_PROXIES is '*' or a comma-separated
        // list of proxy IPs/CIDRs. Trust '*' only when the origin is reachable exclusively through the proxy.
        if (($proxies = trim((string) env('TRUSTED_PROXIES', ''))) !== '') {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }

        // Sanctum first-party SPA: cookie session + CSRF for requests from stateful frontend origins.
        $middleware->statefulApi();
        $middleware->appendToGroup('api', SetRequestLocale::class);

        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'email.verified' => EnsureEmailIsVerified::class,
            'onboarded' => EnsureOnboarded::class,
            'farm.context' => ResolveFarmContext::class,
            'farm.permission' => RequireFarmPermission::class,
            'entitlement' => RequireEntitlement::class,
            'platform.admin' => RequirePlatformAdmin::class,
        ]);

        // Every farm-management endpoint (Phase 2+) uses this: authenticated, active, verified, onboarded.
        $middleware->appendToGroup('app.access', [
            'auth:sanctum',
            'account.active',
            'email.verified',
            'onboarded',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(ApiExceptionRenderer::shouldRender(...));
        $exceptions->render(new ApiExceptionRenderer);
    })->create();
