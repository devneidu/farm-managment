<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListingRequest extends FormRequest
{
    use ListingFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->listingRules(creating: false) + [
            /** The `version` you loaded. A different current version answers 409 stale_listing. Strongly recommended. */
            'version' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
