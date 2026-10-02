<?php

namespace App\Http\Requests\Work;

use App\Enums\FarmRole;
use App\Enums\Recurrence;
use App\Enums\TaskCategory;
use App\Services\Work\LinkedRecords;
use Illuminate\Validation\Rule;

/** Rule fragments shared by tasks, schedules and template items. */
final class WorkRules
{
    /** @return array<string, mixed> */
    public static function recurrence(string $prefix = ''): array
    {
        return [
            $prefix.'recurrence' => ['sometimes', Rule::enum(Recurrence::class)],
            /** Every N days (daily) or N weeks (weekly). */
            $prefix.'interval_value' => ['sometimes', 'integer', 'min:1', 'max:52'],
            /** Weekly only: ISO weekdays 1 (Monday) to 7 (Sunday). Defaults to the weekday of the start. */
            $prefix.'weekdays' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            $prefix.'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            $prefix.'occurrence_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /** @return array<string, mixed> */
    public static function work(string $prefix = ''): array
    {
        return [
            $prefix.'title' => ['required', 'string', 'max:200'],
            $prefix.'category' => ['required', Rule::enum(TaskCategory::class)],
            $prefix.'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            /** Farm-local time of day (HH:MM). Without it the task is due by the end of its day. */
            $prefix.'due_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            /** Minutes before due to remind (stored for the notification phase; nothing is sent yet). */
            $prefix.'reminder_offsets' => ['sometimes', 'nullable', 'array', 'max:5'],
            $prefix.'reminder_offsets.*' => ['integer', 'min:0', 'max:43200'],
            /** The kind of actual record that evidences this task: an operational record type code, health, breeding_check or breeding_outcome. */
            $prefix.'linked_record_type' => ['sometimes', 'nullable', Rule::in(LinkedRecords::types())],
            $prefix.'requires_evidence' => ['sometimes', 'boolean'],
            $prefix.'assigned_role' => ['sometimes', 'nullable', Rule::enum(FarmRole::class)],
        ];
    }
}
