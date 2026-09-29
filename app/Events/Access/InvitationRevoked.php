<?php

namespace App\Events\Access;

use App\Models\FarmInvitation;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Security-relevant fact dispatched after commit; a later audit-log module can listen without changing services. */
class InvitationRevoked
{
    use Dispatchable;

    public function __construct(public readonly FarmInvitation $invitation, public readonly User $actor) {}
}
