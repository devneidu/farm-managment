<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiHttpException;
use Closure;
use Illuminate\Http\Request;

/**
 * Guards /platform-admin/*. Access comes only from a platform_admins grant - never from a farm membership or farm role.
 * `platform.admin` = any platform role (read); `platform.admin:write` = a role that may change configuration.
 */
class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next, string $level = 'read')
    {
        $role = $request->user()?->platformRole();

        if ($role === null) {
            throw new ApiHttpException(403, 'platform_admin_required', 'This area is restricted to platform administrators.');
        }

        if ($level === 'write' && ! $role->canWrite()) {
            throw new ApiHttpException(403, 'platform_write_forbidden', 'Your platform role is read-only.');
        }

        return $next($request);
    }
}
