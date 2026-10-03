<?php

namespace App\Http\Requests\Reports;

use App\Services\Reports\ExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** A report code from GET /reports. */
            'report' => ['required', 'string', 'max:40'],
            'format' => ['required', Rule::in(ExportService::FORMATS)],
            /** The same filters the report accepts. Omitted dates are defaulted when the export is requested, and the resolved filters are stored on the export. */
            'filters' => ['sometimes', 'array'],
            ...ReportRunRequest::filterRules('filters.'),
            /** Required. Retrying the same request returns the same export; reusing the key with different content is a 409. */
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
        ];
    }
}
