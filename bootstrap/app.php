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
