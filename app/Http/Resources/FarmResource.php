<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Farm;
use App\Services\Farm\FarmSettings;
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
     *     settings: array{use_stock_for_usage: bool},
     *     membership: array{id: string, role: 'owner'|'manager'|'farm_worker'|'finance'|'vet', role_label: string, permissions: string[], can_add_stock: bool}
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
            /** Farm preferences (see PATCH /farm). `use_stock_for_usage`: true = items used must be picked from the store. */
            'settings' => FarmSettings::all($this->resource),
            // What the CALLER may do on this farm; drive UI visibility from this, backend still enforces.
            'membership' => [
                'id' => $membership->id,
                'role' => $membership->role->value,
                'role_label' => $membership->role->label(),
                'permissions' => array_map(fn ($p) => $p->value, $membership->role->permissions()),
                /** Whether the caller may add stock (POST /inventory/stock-in needs inventory.add). false: show "Ask your manager to add it to the store" instead of the "+ Add to store" popup. */
                'can_add_stock' => in_array(Permission::InventoryAdd, $membership->role->permissions(), true),
            ],
        ];
    }
}
