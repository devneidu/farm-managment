<?php

namespace App\Events\Measurement;

use App\Models\PackageConversion;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A farm's package conversion was created / changed / deactivated / reactivated. Dispatched after commit so a later
 * audit module can listen without touching the service. `changes` holds old/new values of what changed.
 */
class PackageConversionChanged
{
    use Dispatchable;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DEACTIVATED = 'deactivated';

    public const REACTIVATED = 'reactivated';

    /** @param  array<string, array{old: mixed, new: mixed}>  $changes */
    public function __construct(
        public readonly string $action,
        public readonly PackageConversion $conversion,
        public readonly User $actor,
        public readonly array $changes = [],
    ) {}
}
