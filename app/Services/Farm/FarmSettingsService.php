<?php

namespace App\Services\Farm;

use App\Events\Access\FarmSettingsUpdated;
use App\Models\Farm;
use App\Models\User;

class FarmSettingsService
{
    /**
     * @param  array{name?: string, settings?: array<string, mixed>}  $data
     *
     * Audit/event changes are keyed by field; a setting appears as `settings.<key>` with its from/to.
     */
    public function update(Farm $farm, User $actor, array $data): Farm
    {
        $settings = $data['settings'] ?? [];
        unset($data['settings']);

        $farm->fill($data);
        $original = collect($farm->getDirty())->mapWithKeys(fn ($v, $k) => [$k => ['from' => $farm->getOriginal($k), 'to' => $v]])->all();

        $before = FarmSettings::all($farm);
        $stored = is_array($farm->settings) ? $farm->settings : [];
        foreach ($settings as $key => $value) {
            if ($before[$key] !== $value) {
                $original['settings.'.$key] = ['from' => $before[$key], 'to' => $value];
                $stored[$key] = $value;
            }
        }
        if (array_diff_key($original, ['name' => 1]) !== []) {
            $farm->settings = $stored;
        }

        if ($original !== []) {
            $farm->save();
            FarmSettingsUpdated::dispatch($farm, $original, $actor);
        }

        return $farm;
    }
}
