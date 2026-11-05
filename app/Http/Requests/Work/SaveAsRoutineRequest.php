<?php

namespace App\Http\Requests\Work;

use Illuminate\Foundation\Http\FormRequest;

class SaveAsRoutineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            /** any_batch = every batch; same_kind = only batches of this task's species / crop (or kind); general = no batch. Defaults to any_batch for a batch task, general for one without. */
            'scope' => ['sometimes', 'in:any_batch,same_kind,general'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
        ];
    }
}
