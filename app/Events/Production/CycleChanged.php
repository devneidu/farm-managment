<?php

namespace App\Events\Production;

use App\Models\ProductionCycleEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class CycleChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly ProductionCycleEvent $event) {}
}
