<?php

namespace App\Services\Farm;

use App\Events\Access\FarmSettingsUpdated;
use App\Models\Farm;
use App\Models\User;

class FarmSettingsService
{
    /** @param array{name?: string} $data */
    public function update(Farm $farm, User $actor, array $data): Farm
    {
        $farm->fill($data);
        $changes = $farm->getDirty();
        $original = collect($changes)->mapWithKeys(fn ($v, $k) => [$k => ['from' => $farm->getOriginal($k), 'to' => $v]])->all();

        if ($changes !== []) {
            $farm->save();
            FarmSettingsUpdated::dispatch($farm, $original, $actor);
        }

        return $farm;
    }
}
