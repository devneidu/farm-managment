<?php

namespace App\Http\Resources;

use App\Enums\Capability;
use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every known capability for a species, enabled or not, so forms can decide what to show without knowing species
 * names. `reference` holds biological REFERENCE defaults (e.g. `{incubation_days: 21}`) or null - they are
 * starting points, not guarantees: farm targets and actual outcomes are separate concepts recorded elsewhere.
 *
 * @property Species $resource
 */
class SpeciesCapabilitiesResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     species: array{id: string, code: string, name: string},
     *     operation: array{id: string, code: string, name: string, category: string, tracking_model: string},
     *     capabilities: array<int, array{code: string, label: string, enabled: bool, reference: array<string, int|bool|string>|null}>
     * }
     */
    public function toArray(Request $request): array
    {
        $rows = $this->speciesCapabilities->keyBy(fn ($c) => $c->capability->code);

        return [
            'species' => ['id' => $this->id, 'code' => $this->code, 'name' => $this->name],
            'operation' => OperationTypeResource::summary($this->operationType),
            'capabilities' => array_map(function (Capability $capability) use ($rows) {
                $row = $rows->get($capability->value);

                return [
                    'code' => $capability->value,
                    'label' => $capability->label(),
                    'enabled' => (bool) $row?->enabled,
                    'reference' => $row?->enabled ? $row->reference_config : null,
                ];
            }, Capability::cases()),
        ];
    }
}
