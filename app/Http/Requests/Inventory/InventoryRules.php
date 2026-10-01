<?php

namespace App\Http\Requests\Inventory;

/** Shared rule fragments so every inventory write validates quantities, time and retry keys identically. */
final class InventoryRules
{
    /** Keys a client must never send: balances are derived and ownership comes from FarmContext. */
    public static function forbidden(): array
    {
        $rules = [];
        foreach (['farm_id', 'quantity', 'quantity_delta', 'quantity_on_hand', 'balance', 'stock', 'created_by', 'type'] as $key) {
            $rules[$key] = ['missing'];
        }

        return $rules;
    }

    /** A compound quantity: [{quantity, unit}, ...] normalised by the Phase 5 QuantityNormalizer. */
    public static function components(string $key, bool $required = true): array
    {
        return [
            $key => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            $key.'.*' => ['required', 'array:quantity,unit'],
            $key.'.*.quantity' => ['required', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                    $fail('A scalar decimal quantity is required.');
                }
            }],
            $key.'.*.unit' => ['required', 'string', 'max:32'],
        ];
    }

    public static function event(): array
    {
        return [
            'recorded_at' => ['required', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/'],
            'idempotency_key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    public static function lotChoice(): array
    {
        return [
            'lot_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
