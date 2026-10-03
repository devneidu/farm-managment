<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAdminController extends Controller
{
    /**
     * Platform admin identity
     *
     * Who the caller is on the platform and what they may do. A caller with no platform grant receives `403 platform_admin_required`
     * (a farm Owner or Manager has no platform access). `can_write` is false for the read-only `support` role.
     *
     * @response array{data: array{user: array{id: string, name: string|null, email: string}, role: 'admin'|'support', can_write: bool}, meta: object, message: string|null}
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->platformRole();

        return ApiResponse::success(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email], 'role' => $role->value, 'can_write' => $role->canWrite()]);
    }
}
