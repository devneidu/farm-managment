<?php

namespace App\Http\Resources;

use App\Models\Farm;
use App\Support\Access\FarmContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Farm $resource */
class FarmResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, name: string, country_code: string, currency: string, timezone: string, locale: string,
     *     membership: array{id: string, role: 'owner'|'manager'|'farm_worker'|'finance', role_label: string, permissions: string[]}
     * }
     */
    public function toArray(Request $request): array
    {
        $membership = app(FarmContext::class)->membership;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'country_code' => $this->country_code,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            // What the CALLER may do on this farm; drive UI visibility from this, backend still enforces.
            'membership' => [
                'id' => $membership->id,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'permissions' => array_map(fn ($p) => $p->value, $membership->role->permissions()),
            ],
        ];
    }
}
