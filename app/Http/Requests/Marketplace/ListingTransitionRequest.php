<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class ListingTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The `version` you loaded. When given and different from the current one the call answers 409 stale_listing. */
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
