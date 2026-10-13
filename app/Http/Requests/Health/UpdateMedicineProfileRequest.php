<?php

namespace App\Http\Requests\Health;

use App\Enums\MedicineType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMedicineProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Default withdrawal period in days applied to new health lines that do not state their own. null clears it. Past records keep the days they used. */
            'default_withdrawal_days' => ['present', 'nullable', 'integer', 'min:0', 'max:3650'],
            /** antibiotic | vaccine | supplement | dewormer | other. Omit to leave it unchanged; null returns it to "not specified". */
            'medicine_type' => ['sometimes', 'nullable', Rule::enum(MedicineType::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
