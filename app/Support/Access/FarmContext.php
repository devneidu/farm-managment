<?php

namespace App\Support\Access;

use App\Enums\Permission;
use App\Models\Farm;
use App\Models\FarmMembership;
use App\Support\Api\ApiHttpException;

/**
 * The resolved "who is acting on which farm" for the current request:
 * user -> ACTIVE membership -> farm. Built only by the farm.context middleware from the
 * authenticated user's own memberships, so a farm id alone never grants access.
 */
final class FarmContext
{
    public function __construct(
        public readonly Farm $farm,
        public readonly FarmMembership $membership,
    ) {}

    public function can(Permission $permission): bool
    {
        return $this->membership->can($permission);
    }

    public function authorize(Permission $permission): void
    {
        if (! $this->can($permission)) {
            throw new ApiHttpException(403, 'forbidden', 'You are not allowed to perform this action.');
        }
    }
}
