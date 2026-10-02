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
            'production_cycle_id' => $this->production_cycle_id, 'breeding_project_id' => $this->breeding_project_id,
            'schedules_created' => $this->schedules_created, 'tasks_created' => $this->tasks_created,
            /** Occurrences whose date had already passed (not created as overdue noise). */
            'skipped_past' => $this->skipped_past,
            /** Items skipped because their anchor date does not exist (e.g. no breeding expectation). */
            'skipped_no_anchor' => $this->skipped_no_anchor,
            'created_by' => $this->created_by, 'created_at' => $this->created_at->toISOString(),
        ];
    }
}
