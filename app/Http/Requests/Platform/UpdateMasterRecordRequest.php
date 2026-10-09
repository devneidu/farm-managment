<?php

namespace App\Http\Requests\Platform;

use App\Enums\LivestockGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update a platform reference record. Only presentation and activation can change: `code`, an operation type's category/tracking model and a
 * record's parent are permanent identity. Species may also change `livestock_group`. Deactivate instead of deleting.
 */
class UpdateMasterRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['name' => ['sometimes', 'string', 'max:150'], 'is_active' => ['sometimes', 'boolean']];

        if (in_array($this->route('kind'), ['operation-types', 'species', 'crop-types', 'reference-values'], true)) {
            $rules['sort_order'] = ['sometimes', 'integer', 'min:0', 'max:100000'];
        }
        if ($this->route('kind') === 'species') {
            $rules['livestock_group'] = ['sometimes', 'nullable', Rule::enum(LivestockGroup::class)];
            $rules['breed_field_label'] = ['sometimes', 'nullable', 'string', 'max:64'];
        }

        return $rules + ['code' => ['missing'], 'category' => ['missing'], 'tracking_model' => ['missing'], 'operation_type_id' => ['missing'], 'species_id' => ['missing'], 'crop_type_id' => ['missing'], 'list' => ['missing'], 'farm_id' => ['missing']];
    }
}
