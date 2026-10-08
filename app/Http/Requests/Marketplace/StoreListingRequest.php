<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class StoreListingRequest extends FormRequest
{
    use ListingFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->listingRules(creating: true);
    }
}
