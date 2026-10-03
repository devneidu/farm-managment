<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class DashboardCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /** First farm-local day of the strip (default: today in the farm's timezone). */
            'from' => ['sometimes', 'date_format:Y-m-d'],
            /** Number of days, 1-31 (default 7). */
            'days' => ['sometimes', 'integer', 'min:1', 'max:31'],
        ];
    }
}
