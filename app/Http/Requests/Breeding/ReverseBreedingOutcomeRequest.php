<?php

namespace App\Http\Requests\Breeding;

use App\Http\Requests\Inventory\InventoryRules;
use Illuminate\Foundation\Http\FormRequest;

class ReverseBreedingOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'recorded_at' => InventoryRules::event()['recorded_at'],
            'idempotency_key' => InventoryRules::event()['idempotency_key'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
