<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class ListingRestrictionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** Shown to the seller and kept in the history and the audit trail. */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
