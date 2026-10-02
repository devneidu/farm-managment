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
            'items' => $this->items->map(fn ($i) => [
                'id' => $i->id, 'position' => $i->position, 'title' => $i->title, 'category' => $i->category->value, 'instructions' => $i->instructions,
                'anchor' => $i->anchor->value, 'offset_days' => $i->offset_days, 'recurrence' => $i->recurrence->value, 'interval_value' => $i->interval_value,
                'weekdays' => $i->weekdays, 'until_offset_days' => $i->until_offset_days, 'occurrence_limit' => $i->occurrence_limit,
                'due_time' => $i->due_time !== null ? substr($i->due_time, 0, 5) : null, 'reminder_offsets' => $i->reminder_offsets,
                'assigned_role' => $i->assigned_role, 'linked_record_type' => $i->linked_record_type, 'requires_evidence' => $i->requires_evidence,
            ])->values(),
        ];
    }
}
