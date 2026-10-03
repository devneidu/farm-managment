<?php

namespace App\Http\Resources\Platform;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Support view of an account. Never includes the password hash, tokens, one-time codes or social-provider identifiers.
 * `farms` appears on the detail view only.
 *
 * @property User $resource
 */
class PlatformUserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            'email_verified' => $u->hasVerifiedEmail(), 'onboarded' => $u->isOnboarded(),
            'status' => $u->isSuspended() ? 'suspended' : 'active', 'suspended_at' => $u->suspended_at?->toIso8601String(),
            'platform_role' => $u->platformAdmin?->role->value,
            'active_farms_count' => $u->getAttribute('active_farms_count'),
            'created_at' => $u->created_at?->toIso8601String(),
        ] + ($u->relationLoaded('memberships') ? [
            'providers' => $u->socialAccounts->pluck('provider')->values()->all(),
            'farms' => $u->memberships->map(fn ($m) => ['farm_id' => $m->farm_id, 'farm_name' => $m->farm?->name, 'role' => $m->role->value, 'status' => $m->status->value])->values()->all(),
        ] : []);
    }
}
