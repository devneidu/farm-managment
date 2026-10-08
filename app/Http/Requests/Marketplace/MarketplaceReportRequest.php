<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(MarketplaceReportService::STATES)],
            'type' => ['sometimes', Rule::in(['content', 'deal'])],
            'reason' => ['sometimes', 'string', 'max:30'],
            'target_type' => ['sometimes', Rule::in(['shop', 'listing'])],
            'target_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'string', 'max:100'],
            'shop_id' => ['sometimes', 'uuid'],
            'listing_id' => ['sometimes', 'uuid'],
            'offer_status' => ['sometimes', Rule::in(['pending', 'accepted', 'rejected', 'expired', 'voided'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
