<?php

namespace App\Events\Breeding;

use App\Models\BreedingOutcome;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once per outcome (including reversals) after commit; a hook for later task/notification/report phases. */
class BreedingOutcomeRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly BreedingOutcome $outcome) {}
}
