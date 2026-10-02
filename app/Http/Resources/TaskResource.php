<?php

namespace App\Http\Resources;

use App\Enums\TaskStatus;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $farm = app(FarmContext::class)->farm;
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($farm->timezone)->toDateString();

        return [
            'id' => $this->id, 'reference' => $this->reference,
            /** Stored lifecycle: open | completed | cancelled. */
            'status' => $this->status->value,
            /**
             * Derived from status, due_at and the farm-local day (never stored): upcoming | due_today | overdue | completed | cancelled.
             *
             * @var 'upcoming'|'due_today'|'overdue'|'completed'|'cancelled'
             */
            'due_state' => $this->dueState($now, $today)->value,
            'title' => $this->title, 'category' => $this->category->value, 'instructions' => $this->instructions,
            /** Farm-local due date and optional time (HH:MM). */
            'due_date' => $this->due_date->toDateString(), 'due_time' => $this->due_time !== null ? substr($this->due_time, 0, 5) : null,
            /** UTC instant after which the open task is overdue. */
            'due_at' => $this->due_at->toISOString(), 'timezone' => $farm->timezone,
            'reminder_offsets' => $this->reminder_offsets,
            'production_cycle_id' => $this->production_cycle_id, 'breeding_project_id' => $this->breeding_project_id,
            'schedule_id' => $this->schedule_id, 'occurrence_date' => $this->occurrence_date?->toDateString(),
            'assigned_user_id' => $this->assigned_user_id, 'assigned_user_name' => $this->assignee?->name, 'assigned_role' => $this->assigned_role,
            /** The kind of ACTUAL record that evidences this task (operational record type, health, breeding_check, breeding_outcome). */
            'linked_record_type' => $this->linked_record_type, 'requires_evidence' => $this->requires_evidence,
            'completion' => $this->status === TaskStatus::Completed ? [
                'completed_at' => $this->completed_at->toISOString(), 'completed_by' => $this->completed_by, 'note' => $this->completion_note,
                /** The already-saved actual record linked at completion; null for a plain completion. */
                'evidence' => $this->evidence_type !== null ? ['type' => $this->evidence_type, 'id' => $this->evidence_id] : null,
            ] : null,
            'cancellation' => $this->status === TaskStatus::Cancelled ? ['cancelled_at' => $this->cancelled_at->toISOString(), 'reason' => $this->cancel_reason] : null,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
