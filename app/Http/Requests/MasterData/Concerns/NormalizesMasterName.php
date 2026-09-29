<?php

namespace App\Http\Requests\MasterData\Concerns;

trait NormalizesMasterName
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $this->input('name')))]);
        }
    }

    /** @return list<string> */
    protected function nameRules(bool $required): array
    {
        return [
            $required ? 'required' : 'sometimes', 'string', 'min:2', 'max:100',
            "regex:/^[\p{L}\p{N}][\p{L}\p{N} .,'()\/&-]*$/u",
        ];
    }
}
