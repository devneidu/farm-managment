<?php

namespace App\Events\Access;

use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Security-relevant fact dispatched after commit; a later audit-log module can listen without changing services. */
class FarmSettingsUpdated
{
    use Dispatchable;

    public function __construct(public readonly Farm $farm, public readonly array $changes, public readonly User $actor) {}
}
