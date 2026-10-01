<?php

namespace App\Http\Resources;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A livestock / fish species. `capability_codes` lists the ENABLED capabilities (see
 * GET /master/species/{species}/capabilities for the full set with reference values).
 *
 * @property Species $resource
 */
class SpeciesResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, code: string, name: string, is_active: bool,
     *     livestock_group: array{code: string, name: string}|null,
     *     operation: array{id: string, code: string, name: string, category: string, tracking_model: string},
     *     capability_codes: string[]
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'livestock_group' => $this->livestock_group ? ['code' => $this->livestock_group->value, 'name' => $this->livestock_group->label()] : null,
            'operation' => OperationTypeResource::summary($this->operationType),
            'capability_codes' => $this->speciesCapabilities
                ->filter(fn ($c) => $c->enabled)
                ->map(fn ($c) => $c->capability->code)->sort()->values()->all(),
        ];
    }
}
