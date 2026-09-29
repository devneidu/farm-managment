<?php

namespace App\Http\Requests\MasterData;

use App\Enums\OperationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query filters shared by the master-data selector endpoints (each endpoint uses the ones that apply to it).
 * Boolean filters accept true/false/1/0.
 */
class MasterDataListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.view route middleware
    }

    protected function prepareForValidation(): void
    {
        foreach (['available', 'include_inactive'] as $key) {
            $value = $this->query($key);
            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $this->merge([$key => strtolower($value) === 'true']);
            }
        }
    }

    public function rules(): array
    {
        return [
            'operation' => ['sometimes', 'string', 'max:64'],
            'category' => ['sometimes', 'string', Rule::enum(OperationCategory::class)],
            'available' => ['sometimes', 'boolean'],
            'include_inactive' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{operation?: string, category?: string, available: bool, include_inactive: bool} */
    public function filters(): array
    {
        return [
            'operation' => $this->validated('operation'),
            'category' => $this->validated('category'),
            'available' => $this->boolean('available'),
            'include_inactive' => $this->boolean('include_inactive'),
        ];
    }
}
