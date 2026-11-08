<?php

namespace App\Http\Requests\Farm;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorised by the farm.permission:farm.update route middleware
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/', ' ', $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        // The farm name and the farm settings are editable; country/currency/timezone/language keep platform defaults.
        return [
            'name' => ['required_without:settings', 'string', 'min:2', 'max:120'],
            /** Per-farm preferences; send only the keys you change. */
            'settings' => ['sometimes', 'array:use_stock_for_usage', 'min:1'],
            /** true: every record of feed, fertilizer, pesticide or planting use, and every medicine line, must be picked from the store and takes it out of stock. false (default): a typed name is accepted and no stock moves. */
            'settings.use_stock_for_usage' => ['sometimes', 'boolean:strict'],
        ];
    }
}
