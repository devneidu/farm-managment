<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status, 'title' => $this->title, 'category' => $this->category->value, 'instructions' => $this->instructions,
            'recurrence' => $this->recurrence->value, 'interval_value' => $this->interval_value, 'weekdays' => $this->weekdays,
            'starts_on' => $this->starts_on->toDateString(), 'ends_on' => $this->ends_on?->toDateString(), 'occurrence_limit' => $this->occurrence_limit,
            'due_time' => $this->due_time !== null ? substr($this->due_time, 0, 5) : null, 'reminder_offsets' => $this->reminder_offsets,
            'production_cycle_id' => $this->production_cycle_id, 'breeding_project_id' => $this->breeding_project_id,
            'template_application_id' => $this->template_application_id,
            'assigned_user_id' => $this->assigned_user_id, 'assigned_role' => $this->assigned_role,
            'linked_record_type' => $this->linked_record_type, 'requires_evidence' => $this->requires_evidence,
            'tasks_count' => $this->tasks_count ?? null, 'open_tasks_count' => $this->open_tasks_count ?? null,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
