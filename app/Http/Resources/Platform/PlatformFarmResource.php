<?php

namespace App\Http\Resources\Platform;

use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm in the cross-farm support list: identity, owner, plan and team size only. No farm business data (stock, finance, sales,
 * population, health, breeding) is ever part of a platform-admin response.
 *
 * @property Farm $resource
 */
class PlatformFarmResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $f = $this->resource;
        $owner = $f->ownerMembership?->user;
        $sub = $f->subscription;

        return [
            'id' => $f->id, 'name' => $f->name, 'country_code' => $f->country_code, 'currency' => $f->currency, 'timezone' => $f->timezone,
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email] : null,
            'members_count' => $f->getAttribute('members_count'),
            'plan' => $sub?->plan ? ['id' => $sub->plan->id, 'slug' => $sub->plan->slug, 'name' => $sub->plan->name] : null,
            'subscription_status' => $sub?->status->value,
            'current_period_end' => $sub?->current_period_end?->toIso8601String(),
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
