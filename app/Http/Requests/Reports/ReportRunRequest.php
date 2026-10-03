<?php

namespace App\Http\Requests\Reports;

use App\Enums\CycleKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The shared filter vocabulary. Which of these a given report honours is listed in GET /reports (`filters`); the others are ignored. */
    public static function filterRules(string $prefix = ''): array
    {
        return [
            /** Inclusive farm-local day. Default: 29 days before `to`. */
            $prefix.'from' => ['sometimes', 'date_format:Y-m-d'],
            /** Inclusive farm-local day. Default: today in the farm timezone. */
            $prefix.'to' => ['sometimes', 'date_format:Y-m-d'],
            /** Balance date for point-in-time reports (stock on hand). Default: today. */
            $prefix.'as_of' => ['sometimes', 'date_format:Y-m-d'],
            $prefix.'production_cycle_id' => ['sometimes', 'uuid'],
            $prefix.'kind' => ['sometimes', Rule::in(array_map(fn ($c) => $c->value, CycleKind::cases()))],
            $prefix.'status' => ['sometimes', Rule::in(['active', 'closed', 'completed', 'cancelled'])],
        ];
    }

    public function rules(): array
    {
        return self::filterRules() + [
            /** Rows per page, 1-500 (default 100). Totals in `summary` always cover every row. */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
