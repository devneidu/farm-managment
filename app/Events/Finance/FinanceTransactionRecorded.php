<?php

namespace App\Events\Finance;

use App\Models\FinanceTransaction;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched once per ledger row (entries and reversals) after commit; the hook for later reporting/notification phases. */
class FinanceTransactionRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly FinanceTransaction $transaction) {}
}
