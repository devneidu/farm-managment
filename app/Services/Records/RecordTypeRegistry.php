<?php

namespace App\Services\Records;

use App\Enums\ConversionContextType;
use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Models\ProductionCycle;
use App\Rules\PositiveWholeCount;
use Illuminate\Validation\Rule;

/** Executable, bounded type schemas. There is no client-defined record type or arbitrary detail bag. */
class RecordTypeRegistry
{
    /** Crop treatment context kept apart from the quantity applied: the area treated and the mix strength are never the stock quantity. */
    private const CROP_INPUT_FIELDS = ['treated_area' => ['sometimes', 'nullable', 'array:quantity,unit'], 'concentration' => 'sometimes|nullable|string|max:200'];

    /** Egg / milk production lands in the farm's automatic output stock; the farmer may only choose WHICH store (optional). */
    private const OUTPUT_INVENTORY = ['sometimes', 'array:storage_location_id'];

    public function definitions(): array
    {
        return [
            'feed_use' => ['kind' => 'livestock', 'capability' => 'supports_feed_records', 'dimension' => 'weight', 'unit' => 'kg', 'fields' => ['feed_name' => 'required|string|max:200', 'inventory' => ['sometimes', 'array:item_id,storage_location_id,lot_id']]],
            'egg_collection' => ['kind' => 'livestock', 'capability' => 'produces_eggs', 'dimension' => 'count', 'unit' => 'piece', 'output' => 'eggs', 'fields' => ['inventory' => self::OUTPUT_INVENTORY]],
            'milk' => ['kind' => 'livestock', 'capability' => 'produces_milk', 'dimension' => 'volume', 'unit' => 'l', 'output' => 'milk', 'fields' => ['inventory' => self::OUTPUT_INVENTORY]],
            'mortality' => ['kind' => 'livestock', 'capability' => 'supports_mortality', 'fields' => ['quantity' => ['required', new PositiveWholeCount], 'cause' => 'required|string|max:500']],
            'weight' => ['kind' => 'livestock', 'capability' => 'supports_live_weight', 'dimension' => 'weight', 'unit' => 'kg', 'fields' => ['sample_size' => ['required', new PositiveWholeCount]]],
            'temperature' => ['kind' => 'livestock', 'dimension' => 'temperature', 'unit' => 'celsius', 'fields' => []],
            'water' => ['kind' => 'livestock', 'dimension' => 'volume', 'unit' => 'l', 'fields' => []],
            'irrigation' => ['kind' => 'crop', 'dimension' => 'volume', 'unit' => 'l', 'optional_measurement' => true, 'fields' => ['method' => 'required|string|max:200', 'duration_minutes' => 'sometimes|integer|min:1|max:10080']],
            'weeding' => ['kind' => 'crop', 'fields' => ['method' => 'required|string|max:200']],
            'fertilizer_application' => ['kind' => 'crop', 'dimension' => 'weight', 'unit' => 'kg', 'area_fields' => ['treated_area'], 'stock' => ['category' => 'fertilizer_agrochemical', 'dimensions' => ['weight', 'volume'], 'direction' => 'out'], 'fields' => ['input_name' => 'required_without:details.inventory|string|max:200', 'method' => 'required|string|max:200', ...self::CROP_INPUT_FIELDS, 'inventory' => ['sometimes', 'array:item_id,storage_location_id,lot_id']]],
            'pesticide_application' => ['kind' => 'crop', 'dimension' => 'weight', 'unit' => 'kg', 'area_fields' => ['treated_area'], 'stock' => ['category' => 'fertilizer_agrochemical', 'dimensions' => ['weight', 'volume'], 'direction' => 'out'], 'fields' => ['input_name' => 'required_without:details.inventory|string|max:200', 'product_type' => 'required|in:insecticide,herbicide,fungicide,other', 'method' => 'required|string|max:200', 'target' => 'sometimes|nullable|string|max:300', 'pre_harvest_interval_days' => 'sometimes|nullable|integer|min:0|max:365', ...self::CROP_INPUT_FIELDS, 'inventory' => ['sometimes', 'array:item_id,storage_location_id,lot_id']]],
            'land_preparation' => ['kind' => 'crop', 'area_fields' => ['treated_area'], 'fields' => ['method' => 'required|string|max:200', 'treated_area' => ['sometimes', 'nullable', 'array:quantity,unit']]],
            'planting' => ['kind' => 'crop', 'dimension' => 'weight', 'unit' => 'kg', 'optional_measurement' => true, 'stock' => ['category' => 'seed_planting_material', 'dimensions' => ['weight', 'count'], 'direction' => 'out'],
                'fields' => ['units_planted' => ['required', new PositiveWholeCount], 'method' => 'sometimes|nullable|string|max:200', 'inventory' => ['sometimes', 'array:item_id,storage_location_id,lot_id']]],
            'establishment_check' => ['kind' => 'crop', 'fields' => ['established_units' => ['required', 'regex:/^(0|[1-9][0-9]{0,11})$/']]],
            'growth_stage' => ['kind' => 'crop', 'fields' => ['stage' => 'required|in:germination,seedling,vegetative,flowering,fruiting,maturity,dormant', 'observation' => 'sometimes|nullable|string|max:2000']],
            'crop_loss' => ['kind' => 'crop', 'area_fields' => ['affected_area'], 'fields' => ['units_lost' => ['required', new PositiveWholeCount], 'cause' => 'required|string|max:500', 'affected_area' => ['sometimes', 'nullable', 'array:quantity,unit']]],
            'crop_harvest' => ['kind' => 'crop', 'dimension' => 'weight', 'unit' => 'kg', 'stock' => ['category' => 'produce', 'dimensions' => ['weight', 'volume', 'count'], 'direction' => 'in'],
                'fields' => ['quality' => 'sometimes|nullable|string|max:200', 'inventory' => ['sometimes', 'array:item_id,storage_location_id,lot_id,lot']]],
            'pest_observation' => ['kind' => 'crop', 'fields' => ['issue' => 'required|string|max:500', 'severity' => 'required|in:low,moderate,high', 'action' => 'sometimes|nullable|string|max:2000']],
            'general_note' => ['kind' => null, 'fields' => ['text' => 'required|string|max:5000']],
            'population_adjustment' => ['kind' => 'livestock', 'fields' => ['expected_population' => ['required', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'actual_population' => ['required', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'reason' => 'required|string|max:2000']],
        ];
    }

    public function definition(string $type): array
    {
        return $this->definitions()[$type] ?? abort(404);
    }

    public function detailRules(string $type): array
    {
        $d = $this->definition($type);
        $keys = array_keys($d['fields']);
        if (isset($d['dimension'])) {
            $keys[] = 'components';
            $keys[] = 'context';
        }
        $rules = ['details' => ['present', 'array:'.implode(',', $keys)]];
        foreach ($d['fields'] as $key => $rule) {
            $rules['details.'.$key] = $rule;
        }
        foreach ($d['area_fields'] ?? [] as $area) {
            $rules += [
                'details.'.$area.'.quantity' => ['required_with:details.'.$area, function ($attribute, $value, $fail) {
                    if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                        $fail('A scalar decimal quantity is required.');
                    }
                }],
                'details.'.$area.'.unit' => ['required_with:details.'.$area, 'string', 'max:32'],
            ];
        }
        if ($type === 'feed_use' || isset($d['stock'])) {
            $rules += [
                'details.inventory.item_id' => ['required_with:details.inventory', 'uuid'],
                'details.inventory.storage_location_id' => ['required_with:details.inventory', 'uuid'],
                'details.inventory.lot_id' => ['sometimes', 'nullable', 'uuid'],
            ];
            if (($d['stock']['direction'] ?? null) === 'in') {
                // Harvest may open a new lot (code + optional expiry) or add to an existing one.
                $rules += [
                    'details.inventory.lot' => ['sometimes', 'array:code,expires_on', 'missing_with:details.inventory.lot_id'],
                    'details.inventory.lot.code' => ['required_with:details.inventory.lot', 'string', 'max:80'],
                    'details.inventory.lot.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                ];
            }
        }
        if (isset($d['output'])) {
            $rules += ['details.inventory.storage_location_id' => ['required_with:details.inventory', 'uuid']];
        }
        if (isset($d['dimension'])) {
            $rules += [
                'details.components' => [($d['optional_measurement'] ?? false) ? (isset($d['stock']) ? 'required_with:details.context,details.inventory' : 'required_with:details.context') : 'required', 'array', 'min:1', $d['dimension'] === 'temperature' ? 'max:1' : 'max:10'],
                'details.components.*' => ['required', 'array:quantity,unit'],
                'details.components.*.quantity' => ['required', function ($attribute, $value, $fail) {
                    if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                        $fail('A scalar decimal quantity is required.');
                    }
                }],
                'details.components.*.unit' => ['required', 'string', 'max:32'],
                'details.context' => ['sometimes', 'array:type,id', 'required_with:details.context.type,details.context.id'],
                'details.context.type' => ['required_with:details.context', Rule::enum(ConversionContextType::class)],
                'details.context.id' => ['required_with:details.context', 'uuid'],
            ];
        }

        return $rules;
    }

    public function schema(string $type): array
    {
        $d = $this->definition($type);
        $fields = [];
        foreach ($d['fields'] as $key => $rules) {
            $fields[$key] = is_string($rules) ? explode('|', $rules) : array_map(fn ($r) => is_string($r) ? $r : 'positive_whole_count:1..999999999999', $rules);
        }

        return ['type' => $type, 'cycle_kind' => $d['kind'], 'capability' => $d['capability'] ?? null, 'fields' => $fields,
            'measurement' => isset($d['dimension']) ? ['dimension' => $d['dimension'], 'display_unit' => $d['unit'], 'normalized_unit' => match ($d['dimension']) {
                'weight' => 'g', 'volume' => 'ml', default => $d['unit']
            }, 'required' => ! ($d['optional_measurement'] ?? false), 'components_max' => $d['dimension'] === 'temperature' ? 1 : 10] : null,
            /** Top-level details keys that carry an AREA ({quantity, unit}), never the stock/planting-material quantity. */
            'area_fields' => $d['area_fields'] ?? [],
            'population_effect' => match ($type) {
                'mortality' => 'decrease', 'population_adjustment' => 'actual_minus_expected', default => 'none'
            },
            /** The base permission only; kept for compatibility. `permissions_required` is the authoritative, complete list. */
            'permission' => $this->basePermission($type),
            'permissions_required' => $this->permissionsRequired($type),
            'inventory_effect_enabled' => $type === 'feed_use' || isset($d['stock']) || isset($d['output']),
            'inventory_category' => $type === 'feed_use' ? 'feed' : (isset($d['output']) ? 'produce' : ($d['stock']['category'] ?? null)),
            'inventory_direction' => $type === 'feed_use' ? 'out' : (isset($d['output']) ? 'in' : ($d['stock']['direction'] ?? null)),
            /** Always false: stock is never mandatory for a record. Whether a type has a stock effect at all, and how, is `inventory`. */
            'inventory_required' => ($d['stock']['required'] ?? false) === true,
            'inventory_dimensions' => $type === 'feed_use' ? ['weight'] : (isset($d['output']) ? [$d['dimension']] : ($d['stock']['dimensions'] ?? null)),
            /** Eggs and milk: the stock-in is automatic (the farm's own Eggs / Milk output item); details.inventory only picks the store. */
            'inventory_automatic' => isset($d['output']), 'inventory_output' => $d['output'] ?? null,
            'inventory' => $this->inventoryMetadata($type, $d)];
    }

    /**
     * Everything the backend checks before accepting this type, as permission codes. A caller holds what it needs when it has every code in
     * `always`, plus `when_inventory_linked` if it sends details.inventory, plus `when_correcting` if it sets corrects_record_id.
     *
     * @return array{always: list<string>, when_inventory_linked: list<string>, when_correcting: list<string>}
     */
    public function permissionsRequired(string $type): array
    {
        $d = $this->definition($type);
        $always = [$this->basePermission($type)];
        $linked = [];
        if (isset($d['output'])) {
            $always[] = Permission::InventoryUse->value; // egg / milk records always write a stock-in
        } elseif ($type === 'feed_use' || isset($d['stock'])) {
            $linked[] = Permission::InventoryUse->value;
        }

        return ['always' => $always, 'when_inventory_linked' => $linked, 'when_correcting' => [Permission::RecordReverse->value]];
    }

    private function basePermission(string $type): string
    {
        return ($type === 'population_adjustment' ? Permission::RecordAdjust : Permission::RecordCreate)->value;
    }

    /**
     * Structured stock/output integration (null = the type never touches inventory). `fields` lists the details.inventory keys the type accepts
     * and whether each is `required` once details.inventory is sent. Measurement/context object shapes stay in the integration docs.
     *
     * @return array<string, mixed>|null
     */
    private function inventoryMetadata(string $type, array $d): ?array
    {
        if (isset($d['output'])) {
            return ['mode' => 'automatic_output', 'direction' => 'in', 'output' => $d['output'], 'item_category' => 'produce', 'dimensions' => [$d['dimension']],
                'optional' => false, 'creates_item' => true, 'object' => 'details.inventory', 'fields' => ['storage_location_id' => 'optional']];
        }
        if ($type !== 'feed_use' && ! isset($d['stock'])) {
            return null;
        }
        $in = ($d['stock']['direction'] ?? 'out') === 'in';

        return ['mode' => 'optional_link', 'direction' => $in ? 'in' : 'out', 'output' => null, 'item_category' => $type === 'feed_use' ? 'feed' : $d['stock']['category'],
            'dimensions' => $type === 'feed_use' ? ['weight'] : $d['stock']['dimensions'], 'optional' => true, 'creates_item' => false, 'object' => 'details.inventory',
            'fields' => ['item_id' => 'required', 'storage_location_id' => 'required', 'lot_id' => 'optional'] + ($in ? ['lot' => 'optional'] : [])];
    }

    // ------------------------------------------------- applicability to a cycle

    /** Why the type cannot be recorded against this cycle (the message record creation answers with, 422 on `type`), or null when it can. */
    public function inapplicableReason(ProductionCycle $cycle, string $type, ?array $capabilityCodes = null): ?string
    {
        $definition = $this->definition($type);
        if ($definition['kind'] !== null && $definition['kind'] !== $cycle->kind->value) {
            return 'This record type is not applicable to this kind of cycle.';
        }
        if (isset($definition['capability']) && ! in_array($definition['capability'], $capabilityCodes ?? $this->capabilityCodes($cycle), true)) {
            return 'The species does not support this record capability.';
        }

        return null;
    }

    /** Capability codes currently enabled for the cycle's species (none for a crop cycle). */
    public function capabilityCodes(ProductionCycle $cycle): array
    {
        return $cycle->livestock?->species->speciesCapabilities()->where('enabled', true)->with('capability')->get()->pluck('capability.code')->filter()->values()->all() ?? [];
    }

    /**
     * The record types that can be created for this cycle right now, by exactly the rules record creation applies (cycle kind, species capability,
     * active cycle). Permissions are not filtered: intersect `permissions_required` with the member's permissions (GET /farm).
     *
     * @return list<array{type: string, permissions_required: array<string, list<string>>}>
     */
    public function availableFor(ProductionCycle $cycle): array
    {
        if ($cycle->status !== CycleStatus::Active) {
            return [];
        }
        $codes = $this->capabilityCodes($cycle);
        $out = [];
        foreach (array_keys($this->definitions()) as $type) {
            if ($this->inapplicableReason($cycle, $type, $codes) === null) {
                $out[] = ['type' => $type, 'permissions_required' => $this->permissionsRequired($type)];
            }
        }

        return $out;
    }
}
