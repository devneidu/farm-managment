<?php

namespace App\Services\Records;

use App\Enums\ConversionContextType;
use App\Rules\PositiveWholeCount;
use Illuminate\Validation\Rule;

/** Executable, bounded type schemas. There is no client-defined record type or arbitrary detail bag. */
class RecordTypeRegistry
{
    public function definitions(): array
    {
        return [
            'feed_use' => ['kind' => 'livestock', 'capability' => 'supports_feed_records', 'dimension' => 'weight', 'unit' => 'kg', 'fields' => ['feed_name' => 'required|string|max:200', 'inventory' => ['sometimes', 'array:item_id,storage_location_id,lot_id']]],
            'egg_collection' => ['kind' => 'livestock', 'capability' => 'produces_eggs', 'dimension' => 'count', 'unit' => 'piece', 'fields' => []],
            'milk' => ['kind' => 'livestock', 'capability' => 'produces_milk', 'dimension' => 'volume', 'unit' => 'l', 'fields' => []],
            'mortality' => ['kind' => 'livestock', 'capability' => 'supports_mortality', 'fields' => ['quantity' => ['required', new PositiveWholeCount], 'cause' => 'required|string|max:500']],
            'weight' => ['kind' => 'livestock', 'capability' => 'supports_live_weight', 'dimension' => 'weight', 'unit' => 'kg', 'fields' => ['sample_size' => ['required', new PositiveWholeCount]]],
            'temperature' => ['kind' => 'livestock', 'dimension' => 'temperature', 'unit' => 'celsius', 'fields' => []],
            'water' => ['kind' => 'livestock', 'dimension' => 'volume', 'unit' => 'l', 'fields' => []],
            'irrigation' => ['kind' => 'crop', 'dimension' => 'volume', 'unit' => 'l', 'optional_measurement' => true, 'fields' => ['method' => 'required|string|max:200', 'duration_minutes' => 'sometimes|integer|min:1|max:10080']],
            'weeding' => ['kind' => 'crop', 'fields' => ['method' => 'required|string|max:200']],
            'fertilizer_application' => ['kind' => 'crop', 'dimension' => 'weight', 'unit' => 'kg', 'fields' => ['input_name' => 'required|string|max:200', 'method' => 'required|string|max:200']],
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
        if ($type === 'feed_use') {
            $rules += [
                'details.inventory.item_id' => ['required_with:details.inventory', 'uuid'],
                'details.inventory.storage_location_id' => ['required_with:details.inventory', 'uuid'],
                'details.inventory.lot_id' => ['sometimes', 'nullable', 'uuid'],
            ];
        }
        if (isset($d['dimension'])) {
            $rules += [
                'details.components' => [($d['optional_measurement'] ?? false) ? 'required_with:details.context' : 'required', 'array', 'min:1', $d['dimension'] === 'temperature' ? 'max:1' : 'max:10'],
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
            'population_effect' => match ($type) {
                'mortality' => 'decrease', 'population_adjustment' => 'actual_minus_expected', default => 'none'
            },
            'permission' => $type === 'population_adjustment' ? 'record.adjust' : 'record.create', 'inventory_effect_enabled' => $type === 'feed_use'];
    }
}
