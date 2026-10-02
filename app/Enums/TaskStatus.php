<?php

namespace App\Enums;

/**
 * Stored task state. Upcoming / due_today / overdue are NOT stored: they are derived from due_at and the farm's current day (see DueState).
 * There is deliberately no "missed" state: an unfinished task stays open and overdue until it is completed or cancelled.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
