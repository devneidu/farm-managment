<?php

namespace App\Events\Access;

use App\Models\FarmMembership;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Security-relevant fact dispatched after commit; a later audit-log module can listen without changing services. */
class MemberRemoved
{
    use Dispatchable;

    public function __construct(public readonly FarmMembership $membership, public readonly User $actor) {}
}
