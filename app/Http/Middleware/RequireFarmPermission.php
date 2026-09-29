<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Support\Access\FarmContext;
use Closure;
use Illuminate\Http\Request;

/** Usage: `farm.permission:team.invite`. Must run after farm.context. */
class RequireFarmPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        app(FarmContext::class)->authorize(Permission::from($permission));

        return $next($request);
    }
}
