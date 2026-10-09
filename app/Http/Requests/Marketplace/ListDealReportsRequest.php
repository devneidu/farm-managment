<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceDealLifecycle;
use App\Services\Marketplace\MarketplaceReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDealReportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(MarketplaceReportService::STATES)],
            'reason' => ['sometimes', 'string', Rule::in(MarketplaceDealLifecycle::REPORT_REASONS)],
            'deal_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
