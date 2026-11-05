<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'source' => $this->source, 'code' => $this->code, 'name' => $this->name, 'description' => $this->description, 'applies_to' => $this->applies_to,
            'cycle_kind' => $this->cycle_kind, 'operation_type_id' => $this->operation_type_id, 'species_id' => $this->species_id, 'crop_type_id' => $this->crop_type_id,
            'breeding_workflow' => $this->breeding_workflow, 'version' => $this->version, 'is_active' => $this->is_active, 'cloned_from_id' => $this->cloned_from_id,
            /** Present on the recommended endpoint: the id of the application when this template was already applied to the target, else null. */
            'application_id' => $this->getAttribute('application_id'),
            /** Number of steps. */
            'step_count' => $this->getAttribute('step_count'),
            /** any_batch (every batch), specific (limited by kind / species / crop / workflow) or general (no batch). */
            'scope' => $this->getAttribute('scope'),
            'scope_label' => $this->getAttribute('scope_label'),
            /** Present when a batch was given (?production_cycle_id / ?breeding_project_id): does this routine fit it. */
            'suits_batch' => $this->when(array_key_exists('suits_batch', $this->getAttributes()), fn () => $this->getAttribute('suits_batch')),
            'items' => $this->items->map(fn ($i) => [
                'id' => $i->id, 'position' => $i->position, 'title' => $i->title, 'category' => $i->category->value, 'instructions' => $i->instructions,
                'anchor' => $i->anchor->value, 'offset_days' => $i->offset_days, 'recurrence' => $i->recurrence->value, 'interval_value' => $i->interval_value,
                'weekdays' => $i->weekdays, 'until_offset_days' => $i->until_offset_days, 'occurrence_limit' => $i->occurrence_limit,
                'due_time' => $i->due_time !== null ? substr($i->due_time, 0, 5) : null, 'reminder_offsets' => $i->reminder_offsets, 'reminders' => $i->reminder_presets,
                /** The same repeat block as POST /tasks: {pattern, every, weekdays, ends:{type: days|date|batch_end|never|count}} or null for a one-off. */
                'repeat' => $i->recurrence->value === 'none' ? null : ['pattern' => $i->recurrence->value, 'every' => $i->interval_value, 'weekdays' => $i->weekdays, 'ends' => match (true) {
                    $i->ends_type === 'batch_end' => ['type' => 'batch_end'],
                    $i->ends_type === 'date' => ['type' => 'date', 'date' => $i->ends_on?->toDateString()],
                    $i->until_offset_days !== null => ['type' => 'days', 'days' => $i->until_offset_days - $i->offset_days + 1],
                    $i->occurrence_limit !== null => ['type' => 'count', 'count' => $i->occurrence_limit],
                    default => ['type' => 'never'],
                }],
                'assigned_role' => $i->assigned_role, 'linked_record_type' => $i->linked_record_type, 'requires_evidence' => $i->requires_evidence,
            ])->values(),
        ];
    }
}
