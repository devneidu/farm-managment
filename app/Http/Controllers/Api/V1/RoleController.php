<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FarmRole;
use App\Http\Controllers\Controller;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * List roles
     *
     * The MVP roles (owner, manager, farm worker, finance, vet preset) with their permission presets. `assignable` tells the UI which roles the
     * CALLER may invite or assign (Owner is never assignable: ownership transfer is not supported yet).
     *
     * @response array{data: array<int, array{id: 'owner'|'manager'|'farm_worker'|'finance'|'vet', label: string, permissions: string[], assignable: bool}>, meta: object, message: string|null}
     */
    public function index(FarmContext $ctx): JsonResponse
    {
        $assignable = $ctx->membership->role->assignableRoles();

        $roles = array_map(fn (FarmRole $role) => [
            'id' => $role->value,
            'label' => $role->label(),
            'permissions' => array_map(fn ($p) => $p->value, $role->permissions()),
            'assignable' => in_array($role, $assignable, true),
        ], FarmRole::cases());

        return ApiResponse::success($roles);
    }
}
