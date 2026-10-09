<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** The promotion package to buy (from `GET shops/{shop}/promotion-packages`). */
            'package_id' => ['required', 'uuid'],
        ];
    }
}
