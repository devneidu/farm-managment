<?php

namespace App\Events\Health;

use App\Models\HealthRecord;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once per health record (including reversals) after commit; the hook for later task/notification/report phases. */
class HealthRecordCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly HealthRecord $record) {}
}
