<?php

namespace App\Http\Requests\Work;

use App\Enums\BreedingWorkflow;
use App\Enums\CycleKind;
use App\Enums\TemplateAnchor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /** production_cycle = for a batch (any batch, or limited by the kind/species/crop filters); breeding_project; farm = a general routine with no batch. */
            'applies_to' => ['required', 'in:production_cycle,breeding_project,farm'],
            'cycle_kind' => ['sometimes', 'nullable', Rule::enum(CycleKind::class)],
            'operation_type_id' => ['sometimes', 'nullable', 'uuid', 'exists:operation_types,id'],
            'species_id' => ['sometimes', 'nullable', 'uuid', 'exists:species,id'],
            'crop_type_id' => ['sometimes', 'nullable', 'uuid', 'exists:crop_types,id'],
            'breeding_workflow' => ['sometimes', 'nullable', Rule::enum(BreedingWorkflow::class)],
            'is_active' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array'],
            /** Defaults to start_date (the date chosen when the routine is applied). The older anchors only fit batch / breeding routines. */
            'items.*.anchor' => ['sometimes', Rule::enum(TemplateAnchor::class)],
            /** Days after the anchor date of the first task. */
            'items.*.offset_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            /** Recurring items: last day, as days after the anchor. */
            'items.*.until_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            /** @ignoreParam */
            'farm_id' => ['missing'],
            /** @ignoreParam */
            'source' => ['missing'],
            /** @ignoreParam */
            'version' => ['missing'],
            ...WorkRules::work('items.*.'),
            ...WorkRules::recurrence('items.*.'),
            ...WorkRules::reminders('items.*.'),
            ...WorkRules::repeat('items.*.'),
        ];
    }
}
