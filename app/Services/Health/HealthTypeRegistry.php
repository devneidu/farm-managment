<?php

namespace App\Services\Health;

/**
 * Executable, bounded health record types. Types drive the labels and required fields; there is no client-defined type
 * and no free-form detail bag. Medicine lines are first-class rows (health_record_medicines), never part of `details`.
 */
class HealthTypeRegistry
{
    public const REVERSAL = 'reversal';

    public function definitions(): array
    {
        return [
            'vaccination' => ['label' => 'Vaccination', 'kinds' => ['livestock'], 'medicines' => 'required', 'fields' => ['target_disease' => 'required|string|max:200']],
            'medication' => ['label' => 'Medication', 'kinds' => ['livestock'], 'medicines' => 'required', 'fields' => ['condition' => 'required|string|max:300']],
            'deworming' => ['label' => 'Deworming', 'kinds' => ['livestock'], 'medicines' => 'required', 'fields' => ['parasite_target' => 'sometimes|nullable|string|max:200']],
            'treatment' => ['label' => 'Treatment', 'kinds' => ['livestock'], 'medicines' => 'required', 'fields' => ['condition' => 'required|string|max:300']],
            'disease_issue' => ['label' => 'Disease / issue', 'kinds' => ['livestock'], 'medicines' => 'forbidden', 'fields' => ['condition' => 'required|string|max:300', 'severity' => 'required|in:low,moderate,high', 'symptoms' => 'sometimes|nullable|string|max:2000']],
            'vet_visit' => ['label' => 'Vet visit', 'kinds' => ['livestock'], 'medicines' => 'forbidden', 'fields' => ['vet_name' => 'required|string|max:150', 'findings' => 'sometimes|nullable|string|max:5000']],
        ];
    }

    public function definition(string $type): array
    {
        return $this->definitions()[$type] ?? abort(404);
    }

    public function detailRules(string $type): array
    {
        $rules = ['details' => ['present', 'array:'.implode(',', array_keys($this->definition($type)['fields']))]];
        foreach ($this->definition($type)['fields'] as $key => $rule) {
            $rules['details.'.$key] = $rule;
        }

        return $rules;
    }

    public function schema(string $type): array
    {
        $d = $this->definition($type);

        return [
            'type' => $type, 'label' => $d['label'], 'cycle_kinds' => $d['kinds'],
            'fields' => array_map(fn ($rules) => explode('|', $rules), $d['fields']),
            'medicines' => $d['medicines'],
            'medicine_category' => $d['medicines'] === 'forbidden' ? null : 'medicine',
            'common_fields' => ['animals_affected' => 'livestock only, whole count <= current population', 'follow_up_on' => 'Y-m-d, not before the event day', 'mortality_record_id' => 'livestock only, an existing Phase 8 mortality record of the same cycle', 'notes' => 'string'],
            'population_effect' => 'none', 'stock_effect' => $d['medicines'] === 'forbidden' ? 'none' : 'one stock_out per medicine line',
        ];
    }
}
