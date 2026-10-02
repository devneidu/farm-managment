<?php

namespace App\Events\Purchasing;

use App\Models\Purchase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit when a purchase is recorded or cancelled. */
class PurchaseRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Purchase $purchase, public readonly string $action = 'created') {}
}
