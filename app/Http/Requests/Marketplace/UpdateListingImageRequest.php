<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class UpdateListingImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:160'],
            /** 0-based position. 0 is the primary photo; the others are renumbered. */
            'position' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ];
    }
}
