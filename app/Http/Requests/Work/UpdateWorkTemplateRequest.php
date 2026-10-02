<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Same shape as create; every top-level field is optional and a supplied items list replaces the previous one (version + 1). */
    public function rules(): array
    {
        $rules = (new StoreWorkTemplateRequest)->rules();
        foreach (['name', 'items'] as $field) {
            $rules[$field] = array_map(fn ($r) => $r === 'required' ? 'sometimes' : $r, $rules[$field]);
        }
        $rules['applies_to'] = ['missing'];

        return $rules;
    }
}
