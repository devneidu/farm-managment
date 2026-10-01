<?php

namespace App\Events\Inventory;

use App\Models\InventoryMovement;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once per ledger row after commit (audit/notification hook for later phases, for example low-stock alerts). */
class InventoryMovementRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly InventoryMovement $movement) {}
}
