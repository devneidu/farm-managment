<?php

namespace App\Events\Sales;

use App\Models\Sale;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched after commit when a sale is recorded or cancelled. */
class SaleRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Sale $sale, public readonly string $action = 'created') {}
}
