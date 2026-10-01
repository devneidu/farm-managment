<?php

namespace App\Services\MasterData;

use App\Enums\Capability;
use App\Models\MasterCapability;
use App\Models\Species;
use App\Models\SpeciesCapability;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of species capabilities (used by the seeder today, Platform Admin later). Enforces that
 * `reference_config` contains only the keys the capability allows, each validated: no free-form behaviour in JSON.
 * The stored values are biological REFERENCE defaults - never farm targets and never actual observed outcomes.
 */
class SpeciesCapabilityService
{
    public function set(Species $species, Capability $capability, bool $enabled = true, ?array $config = null): SpeciesCapability
    {
        $config = $this->validatedConfig($capability, $config);

        $registry = MasterCapability::firstOrCreate(['code' => $capability->value], ['name' => $capability->label()]);

        return SpeciesCapability::updateOrCreate(
            ['species_id' => $species->id, 'capability_id' => $registry->id],
            ['enabled' => $enabled, 'reference_config' => $config],
        );
    }

    /** @return array<string, mixed>|null */
    public function validatedConfig(Capability $capability, ?array $config): ?array
    {
        if ($config === null || $config === []) {
            return null;
        }

        $rules = $capability->configRules();

        $validator = Validator::make($config, $rules);
        $unknown = array_diff(array_keys($config), array_keys($rules));

        if ($unknown !== []) {
            $validator->after(function ($v) use ($unknown) {
                foreach ($unknown as $key) {
                    $v->errors()->add($key, "The {$key} key is not allowed for this capability.");
                }
            });
        }

        // A default, when present, must sit inside the range.
        foreach (array_keys($rules) as $key) {
            $base = substr($key, 0, -4);
            if (str_ends_with($key, '_min') && isset($config[$base], $config[$key], $config[$base.'_max'])) {
                $validator->after(function ($v) use ($config, $base) {
                    if ($config[$base] < $config[$base.'_min'] || $config[$base] > $config[$base.'_max']) {
                        $v->errors()->add($base, 'The default must lie within the min/max range.');
                    }
                });
            }
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $config;
    }
}
