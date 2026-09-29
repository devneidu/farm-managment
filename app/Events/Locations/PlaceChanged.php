<?php

namespace App\Events\Locations;

use App\Models\Place;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Audit hook only; no audit store yet. A no-op PATCH emits nothing. */
class PlaceChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param array<string, array{old: mixed, new: mixed}> $changes */
    public function __construct(
        public readonly string $action,
        public readonly Place $place,
        public readonly User $actor,
        public readonly array $changes,
    ) {}
}
