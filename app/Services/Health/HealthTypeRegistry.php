<?php

namespace App\Services\Health;

use App\Enums\HealthProblemType;

/**
 * Executable, bounded health record types. Types drive the labels and required fields; there is no client-defined type
 * and no free-form detail bag. Medicine lines are first-class rows (health_record_medicines), never part of `details`.
 */
class HealthTypeRegistry
{
    public const REVERSAL = 'reversal';

    public const TEXT_FIELD_LABEL = 'Disease name (if known)';

    public function definitions(): array
    {
        // The type's one free-text "disease name" box is optional, and only required when the problem type is "other".
        $other = 'required_if:details.problem_type,'.HealthProblemType::Other->value;

        return [
            'vaccination' => ['label' => 'Vaccination', 'kinds' => ['livestock'], 'medicines' => 'required', 'question' => 'What is it protecting against?', 'default_problem_type' => null, 'text_field' => 'target_disease',
                'fields' => ['target_disease' => $other.'|nullable|string|max:200']],
            'medication' => ['label' => 'Medication', 'kinds' => ['livestock'], 'medicines' => 'required', 'question' => 'What is it for?', 'default_problem_type' => null, 'text_field' => 'condition',
                'fields' => ['condition' => $other.'|nullable|string|max:300']],
            'deworming' => ['label' => 'Deworming', 'kinds' => ['livestock'], 'medicines' => 'required', 'question' => 'What is it for?', 'default_problem_type' => HealthProblemType::WormsInternalParasites->value, 'text_field' => 'parasite_target',
                'fields' => ['parasite_target' => $other.'|nullable|string|max:200']],
            'treatment' => ['label' => 'Treatment', 'kinds' => ['livestock'], 'medicines' => 'required', 'question' => 'What are you treating?', 'default_problem_type' => null, 'text_field' => 'condition',
                'fields' => ['condition' => $other.'|nullable|string|max:300']],
            'disease_issue' => ['label' => 'Disease / issue', 'kinds' => ['livestock'], 'medicines' => 'forbidden', 'question' => "What's the problem?", 'default_problem_type' => null, 'text_field' => 'condition',
                'fields' => ['condition' => $other.'|nullable|string|max:300', 'severity' => 'required|in:low,moderate,high', 'symptoms' => 'sometimes|nullable|string|max:2000']],
            'vet_visit' => ['label' => 'Vet visit', 'kinds' => ['livestock'], 'medicines' => 'forbidden', 'question' => 'Reason for the visit?', 'default_problem_type' => null, 'text_field' => 'condition',
                'fields' => ['condition' => $other.'|nullable|string|max:300', 'vet_name' => 'required|string|max:150', 'findings' => 'sometimes|nullable|string|max:5000']],
        ];
    }

    public function definition(string $type): array
    {
        return $this->definitions()[$type] ?? abort(404);
    }

    /** `problem_type` is common to every type, so it is prepended here and the per-type field lists stay about the type's own details. */
    private function fieldRules(string $type): array
    {
        return ['problem_type' => 'sometimes|nullable|in:'.implode(',', array_column(HealthProblemType::cases(), 'value'))] + $this->definition($type)['fields'];
    }

    /** What is stored when the client omits `problem_type` (old clients): worms for deworming, otherwise "other". */
    public function problemTypeOrDefault(string $type, ?string $given): string
    {
        return $given ?? $this->definition($type)['default_problem_type'] ?? HealthProblemType::Other->value;
    }

    public function detailRules(string $type): array
    {
        $rules = ['details' => ['present', 'array:'.implode(',', array_keys($this->fieldRules($type)))]];
        foreach ($this->fieldRules($type) as $key => $rule) {
            $rules['details.'.$key] = $rule;
        }

        return $rules;
    }

    public function detailMessages(string $type): array
    {
        $field = $this->definition($type)['text_field'];

        return ['details.'.$field.'.required_if' => 'Describe the problem in "'.self::TEXT_FIELD_LABEL.'" when the problem type is Other.'];
    }

    public function schema(string $type): array
    {
        $d = $this->definition($type);

        return [
            'type' => $type, 'label' => $d['label'], 'cycle_kinds' => $d['kinds'],
            'fields' => array_map(fn ($rules) => explode('|', $rules), $this->fieldRules($type)),
            /** The "what is the problem" dropdown (same list for every type). `problem_type` is optional on write; when `other`, the `text_field.key` detail becomes required. */
            'problem_types' => array_map(fn (HealthProblemType $p) => ['code' => $p->value, 'label' => $p->label()], HealthProblemType::cases()),
            'question' => $d['question'], 'default_problem_type' => $d['default_problem_type'],
            'text_field' => ['key' => $d['text_field'], 'label' => self::TEXT_FIELD_LABEL, 'required_when_problem_type' => HealthProblemType::Other->value],
            'medicines' => $d['medicines'],
            'medicine_category' => $d['medicines'] === 'forbidden' ? null : 'medicine',
            'common_fields' => ['animals_affected' => 'livestock only, whole count <= current population', 'follow_up_on' => 'Y-m-d, not before the event day', 'mortality_record_id' => 'livestock only, an existing Phase 8 mortality record of the same cycle', 'notes' => 'string'],
            'population_effect' => 'none', 'stock_effect' => $d['medicines'] === 'forbidden' ? 'none' : 'one stock_out per medicine line',
        ];
    }
}
