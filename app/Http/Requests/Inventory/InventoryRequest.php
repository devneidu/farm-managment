<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Base for inventory requests. Derived fields (balances) and ownership (farm_id) are rejected with 422 instead of being
 * silently ignored; the check lives here (not in rules()) so they do not appear as accepted fields in the OpenAPI schema.
 */
abstract class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        if (! $this->isMethod('GET')) {
            $validator->addRules(InventoryRules::forbidden());
        }
    }
}
