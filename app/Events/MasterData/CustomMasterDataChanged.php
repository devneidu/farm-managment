<?php

namespace App\Events\MasterData;

use App\Models\Breed;
use App\Models\CropVariety;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A farm's custom master record was created / renamed / deactivated / reactivated. Dispatched after commit so a
 * later audit module can listen without touching the service. `changes` holds old/new values of what changed.
 */
class CustomMasterDataChanged
{
    use Dispatchable;

    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DEACTIVATED = 'deactivated';

    public const REACTIVATED = 'reactivated';

    /** @param  array<string, array{old: mixed, new: mixed}>  $changes */
    public function __construct(
        public readonly string $action,
        public readonly Breed|CropVariety $record,
        public readonly User $actor,
        public readonly array $changes = [],
    ) {}
}
