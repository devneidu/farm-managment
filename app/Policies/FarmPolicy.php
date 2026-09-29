<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\User;

class FarmPolicy
{
    public function view(User $user, Farm $farm): bool
    {
        return $farm->memberships()->active()->where('user_id', $user->id)->exists();
    }
}
