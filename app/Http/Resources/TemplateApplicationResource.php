<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'work_template_id' => $this->work_template_id, 'template_version' => $this->template_version,
            /** Present on lists and on remove. */
            'template' => $this->whenLoaded('template', fn () => ['id' => $this->template->id, 'name' => $this->template->name, 'source' => $this->template->source]),
            'production_cycle_id' => $this->production_cycle_id, 'breeding_project_id' => $this->breeding_project_id,
            /** active, or removed after POST /template-applications/{id}/remove. */
            'status' => $this->status,
            /** Day 1 of the routine (offset 0). */
            'start_date' => $this->start_date?->toDateString(),
            'schedules_created' => $this->schedules_created, 'tasks_created' => $this->tasks_created,
            /** Occurrences whose date had already passed (not created as overdue noise). */
            'skipped_past' => $this->skipped_past,
            /** Items skipped because their anchor date does not exist (e.g. no breeding expectation). */
            'skipped_no_anchor' => $this->skipped_no_anchor,
            /** Present on lists and on remove: tasks of this application by status now. */
            'task_counts' => $this->when($this->getAttribute('task_counts') !== null, fn () => $this->getAttribute('task_counts')),
            'removed_at' => $this->removed_at?->toISOString(), 'tasks_cancelled' => $this->tasks_cancelled,
            'created_by' => $this->created_by, 'applied_at' => $this->created_at->toISOString(), 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
