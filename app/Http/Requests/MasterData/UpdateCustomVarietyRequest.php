<?php

namespace App\Http\Requests\MasterData;

use App\Http\Requests\MasterData\Concerns\NormalizesMasterName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomVarietyRequest extends FormRequest
{
    use NormalizesMasterName;

    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:master_data.manage route middleware
    }

    public function rules(): array
    {
        return [
            'name' => $this->nameRules(false),
            'is_active' => ['sometimes', 'boolean'],
            'farm_id' => ['missing'],
            'crop_type_id' => ['missing'],
            'code' => ['missing'],
        ];
    }
}
