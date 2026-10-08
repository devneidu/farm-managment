<?php

namespace App\Http\Requests\Marketplace;

use App\Services\Marketplace\MarketplaceReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContentReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(MarketplaceReportService::CONTENT_REASONS)],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
