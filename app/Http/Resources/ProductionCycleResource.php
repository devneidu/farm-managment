<?php

namespace App\Http\Resources;

use App\Enums\CycleKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductionCycleResource extends JsonResource
{
    /**
     * @return array{
     * id: string, kind: 'livestock'|'crop', name: string, reference: string, status: 'active'|'closed',
     * operation: array{id: string, code: string, name: string, tracking_model: string},
     * production_area: PlaceResource|null, start_date: string|null, planting_date: string|null,
     * expected_end_date: string|null, end_date: string|null, notes: string|null, baseline_locked: bool,
     * livestock: array{species: array{id: string, code: string, name: string}, breed: array{id: string, name: string, is_active: bool}|null, initial_population: int, current_population: int, population_unit: string, population_basis: string}|null,
     * crop: array{crop_type: array{id: string, code: string, name: string}, variety: array{id: string, name: string, is_active: bool}|null, planting_material_type: string, planting_material_label: string, planting_unit_type: string, planting_unit_label: string, initial_planting_units: int, expected_germination_date: string|null, area: array{entered: list<array{quantity: string, unit: string}>, normalized: array{quantity: string, unit: string}}|null}|null,
     * created_at: string, updated_at: string
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** @var 'livestock'|'crop' */
            'kind' => $this->kind->value,
            'name' => $this->name,
            'reference' => $this->reference,
            /** @var 'active'|'closed' */
            'status' => $this->status->value,
            'operation' => ['id' => $this->operation->id, 'code' => $this->operation->code, 'name' => $this->operation->name, 'tracking_model' => $this->operation->tracking_model->value],
            /** @var PlaceResource|null */
            'production_area' => $this->productionArea ? (new PlaceResource($this->productionArea))->resolve($request) : null,
            'start_date' => $this->kind === CycleKind::Livestock ? $this->start_date->toDateString() : null,
            'planting_date' => $this->kind === CycleKind::Crop ? $this->start_date->toDateString() : null,
            'expected_end_date' => $this->expected_end_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'baseline_locked' => true,
            'livestock' => $this->livestock ? [
                'species' => ['id' => $this->livestock->species->id, 'code' => $this->livestock->species->code, 'name' => $this->livestock->species->name],
                'breed' => $this->livestock->breed ? ['id' => $this->livestock->breed->id, 'name' => $this->livestock->breed->name, 'is_active' => $this->livestock->breed->is_active] : null,
                'initial_population' => $this->livestock->initial_population,
                'current_population' => (int) $this->current_population,
                'population_unit' => 'head',
                'population_basis' => 'population_movements',
            ] : null,
            'crop' => $this->crop ? [
                'crop_type' => ['id' => $this->crop->cropType->id, 'code' => $this->crop->cropType->code, 'name' => $this->crop->cropType->name],
                'variety' => $this->crop->variety ? ['id' => $this->crop->variety->id, 'name' => $this->crop->variety->name, 'is_active' => $this->crop->variety->is_active] : null,
                'planting_material_type' => $this->crop->materialType->code,
                'planting_material_label' => $this->crop->materialType->name,
                'planting_unit_type' => $this->crop->unitType->code,
                'planting_unit_label' => $this->crop->unitType->name,
                'initial_planting_units' => $this->crop->initial_planting_units,
                'expected_germination_date' => $this->crop->expected_germination_date?->toDateString(),
                'area' => $this->crop->area_measurement === null ? null : [
                    /** @var list<array{quantity: string, unit: string}> */
                    'entered' => $this->crop->area_measurement['entered'],
                    /** @var array{quantity: string, unit: string} */
                    'normalized' => $this->crop->area_measurement['normalized'],
                ],
            ] : null,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
