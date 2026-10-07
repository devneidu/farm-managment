<?php

namespace App\Http\Requests\Marketplace;

use App\Enums\ShopStatus;
use App\Enums\ShopVerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPlatformShopsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('farm_backed')) {
            $this->merge(['farm_backed' => filter_var($this->input('farm_backed'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
    }

    public function rules(): array
    {
        return [
            /** Matches the shop name, slug or reference code. */
            'q' => ['sometimes', 'string', 'max:100'],
            /** `pending_review` returns the review queue, oldest first. */
            'status' => ['sometimes', Rule::enum(ShopStatus::class)],
            'verification_status' => ['sometimes', Rule::enum(ShopVerificationStatus::class)],
            'farm_backed' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
