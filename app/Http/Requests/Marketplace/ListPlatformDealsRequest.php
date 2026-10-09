<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlatformDealsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['accepted', 'completed', 'cancelled'])],
            'shop_id' => ['sometimes', 'uuid'],
            /** true = only deals with at least one report; false = only deals with none. */
            'reported' => ['sometimes', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
            /** Part of the deal reference (DEL-2026-00001). */
            'q' => ['sometimes', 'string', 'max:40'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
