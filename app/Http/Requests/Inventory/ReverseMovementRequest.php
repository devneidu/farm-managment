<?php

namespace App\Http\Requests\Inventory;

class ReverseMovementRequest extends InventoryRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $event = InventoryRules::event();

        return [
            'reason' => ['required', 'string', 'max:2000'],
            'recorded_at' => $event['recorded_at'],
            'idempotency_key' => $event['idempotency_key'],
        ];
    }
}
