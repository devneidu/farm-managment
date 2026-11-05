<?php

namespace App\Http\Requests\Work;

use App\Enums\FarmRole;
use App\Enums\Recurrence;
use App\Enums\TaskCategory;
use App\Services\Work\LinkedRecords;
use App\Services\Work\ReminderPlan;
use Illuminate\Validation\Rule;

/** Rule fragments shared by tasks, schedules and template items. */
final class WorkRules
{
    /**
     * `distinct` on a wildcard path (items.*.weekdays.*) compares values across ALL items, so inside a list of items it is left out and the service
     * removes duplicates instead.
     *
     * @param  list<mixed>  $rules
     * @return list<mixed>
     */
    private static function distinct(string $prefix, array $rules): array
    {
        return str_contains($prefix, '*') ? $rules : [...$rules, 'distinct'];
    }

    /** @return array<string, mixed> */
    public static function recurrence(string $prefix = ''): array
    {
        return [
            $prefix.'recurrence' => ['sometimes', Rule::enum(Recurrence::class)],
            /** Every N days (daily) or N weeks (weekly). */
            $prefix.'interval_value' => ['sometimes', 'integer', 'min:1', 'max:52'],
            /** Weekly only: ISO weekdays 1 (Monday) to 7 (Sunday). Defaults to the weekday of the start. */
            $prefix.'weekdays' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            $prefix.'weekdays.*' => self::distinct($prefix, ['integer', 'between:1,7']),
            $prefix.'occurrence_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /** @return array<string, mixed> */
    public static function reminders(string $prefix = ''): array
    {
        return [
            /** Day-based reminder presets: on_the_day, 1_day, 2_days, 7_days. They fire at 07:00 farm-local (on_the_day never later than 1 hour before the due time). */
            $prefix.'reminders' => ['sometimes', 'nullable', 'array', 'max:4'],
            $prefix.'reminders.*' => self::distinct($prefix, ['string', Rule::in(array_keys(ReminderPlan::PRESETS))]),
        ];
    }

    /** The "repeat" block of the single task form. @return array<string, mixed> */
    public static function repeat(string $prefix = ''): array
    {
        return [
            $prefix.'repeat' => ['sometimes', 'nullable', 'array'],
            /** daily = every N days, weekly = every N weeks on the chosen weekdays, monthly = every N months. */
            $prefix.'repeat.pattern' => ['required_with:'.$prefix.'repeat', Rule::in(['daily', 'weekly', 'monthly'])],
            $prefix.'repeat.every' => ['sometimes', 'integer', 'min:1', 'max:52'],
            /** Weekly only: ISO weekdays 1 (Monday) to 7 (Sunday). */
            $prefix.'repeat.weekdays' => ['sometimes', 'nullable', 'array', 'min:1', 'max:7'],
            $prefix.'repeat.weekdays.*' => self::distinct($prefix, ['integer', 'between:1,7']),
            $prefix.'repeat.ends' => ['sometimes', 'array'],
            /** days (for N days), date, batch_end (while the batch is open) or never. */
            $prefix.'repeat.ends.type' => ['required_with:'.$prefix.'repeat.ends', Rule::in(['days', 'date', 'batch_end', 'never'])],
            $prefix.'repeat.ends.days' => ['required_if:'.$prefix.'repeat.ends.type,days', 'integer', 'min:1', 'max:3650'],
            $prefix.'repeat.ends.date' => ['required_if:'.$prefix.'repeat.ends.type,date', 'date_format:Y-m-d'],
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
            /** Minutes before due to remind (older form; the day-based `reminders` presets are preferred). */
            $prefix.'reminder_offsets' => ['sometimes', 'nullable', 'array', 'max:5'],
            $prefix.'reminder_offsets.*' => ['integer', 'min:0', 'max:43200'],
            /** The kind of actual record that evidences this task: an operational record type code, health, breeding_check or breeding_outcome. */
            $prefix.'linked_record_type' => ['sometimes', 'nullable', Rule::in(LinkedRecords::types())],
            $prefix.'requires_evidence' => ['sometimes', 'boolean'],
            $prefix.'assigned_role' => ['sometimes', 'nullable', Rule::enum(FarmRole::class)],
        ];
    }
}
