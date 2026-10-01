<?php

namespace App\Events\Production;

use App\Models\OperationalRecord;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class RecordCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly OperationalRecord $record) {}
}
