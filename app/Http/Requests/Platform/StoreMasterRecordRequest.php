<?php

namespace App\Http\Requests\Platform;

use App\Enums\LivestockGroup;
use App\Enums\OperationCategory;
use App\Models\ReferenceValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a platform reference record. The fields depend on the `{kind}` path segment:
 * operation-types (code, name, category), species (operation_type_id, code, name, livestock_group), crop-types (operation_type_id, code, name),
 * breeds (species_id, name, code), varieties (crop_type_id, name, code), reference-values (list, code, name).
 * `code` is the permanent machine identity. An operation type's tracking model follows from its category and is never client-supplied.
 */
class StoreMasterRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $code = fn (string $table, ?string $scope = null) => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique($table, 'code')->when($scope, fn ($r) => $r->where($scope, $this->input($scope)))];
        $common = ['name' => ['required', 'string', 'max:150'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'], 'is_active' => ['sometimes', 'boolean']];

        return match ($this->route('kind')) {
            'operation-types' => $common + ['code' => $code('operation_types'), 'category' => ['required', Rule::enum(OperationCategory::class)], 'tracking_model' => ['missing']],
            'species' => $common + ['code' => $code('species'), 'operation_type_id' => ['required', 'uuid', 'exists:operation_types,id'], 'livestock_group' => ['sometimes', 'nullable', Rule::enum(LivestockGroup::class)]],
            'crop-types' => $common + ['code' => $code('crop_types'), 'operation_type_id' => ['required', 'uuid', 'exists:operation_types,id']],
            'breeds' => ['name' => $common['name'], 'is_active' => $common['is_active'], 'species_id' => ['required', 'uuid', 'exists:species,id'], 'code' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('breeds', 'code')->where('species_id', $this->input('species_id'))]],
            'varieties' => ['name' => $common['name'], 'is_active' => $common['is_active'], 'crop_type_id' => ['required', 'uuid', 'exists:crop_types,id'], 'code' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('crop_varieties', 'code')->where('crop_type_id', $this->input('crop_type_id'))]],
            'reference-values' => $common + ['list' => ['required', Rule::in([ReferenceValue::PLANTING_MATERIAL_TYPE, ReferenceValue::PLANTING_UNIT_TYPE])], 'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('reference_values', 'code')->where('list', $this->input('list'))]],
            default => [],
        };
    }
}
