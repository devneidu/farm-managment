<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/** Presentation state derived from status + due date; never persisted. */
enum DueState: string
{
    case Upcoming = 'upcoming';
    case DueToday = 'due_today';
    case Overdue = 'overdue';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** $dueAt is the instant after which the task is overdue (end of the due day, or the due time); $today the farm-local calendar date. */
    public static function derive(TaskStatus $status, CarbonInterface $dueAt, string $dueDate, CarbonInterface $now, string $today): self
    {
        return match (true) {
            $status === TaskStatus::Completed => self::Completed,
            $status === TaskStatus::Cancelled => self::Cancelled,
            $now->greaterThanOrEqualTo($dueAt) => self::Overdue,
            $dueDate <= $today => self::DueToday,
            default => self::Upcoming,
        };
    }
}
