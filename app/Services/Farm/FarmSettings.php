<?php

namespace App\Services\Farm;

use App\Models\Farm;

/**
 * Typed reader for the farm's preferences (farms.settings JSON). A missing key reads as its default, so a new setting needs only an entry
 * in DEFAULTS (and a validation rule in UpdateFarmRequest): no migration, no backfill. Unknown keys in storage are never returned.
 */
final class FarmSettings
{
    /** key => default. The type of the default is the type of the setting. */
    public const DEFAULTS = [
        /** true: records and health lines must name an item from the store, and consume it. false: a typed name is accepted and no stock moves. */
        'use_stock_for_usage' => false,
    ];

    public static function get(Farm $farm, string $key): mixed
    {
        return self::all($farm)[$key] ?? throw new \InvalidArgumentException('Unknown farm setting: '.$key);
    }

    /** @return array<string, mixed> every known setting with its stored value or default */
    public static function all(Farm $farm): array
    {
        $stored = is_array($farm->settings) ? $farm->settings : [];
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) && gettype($stored[$key]) === gettype($default) ? $stored[$key] : $default;
        }

        return $out;
    }

    public static function usesStockForUsage(Farm $farm): bool
    {
        return (bool) self::get($farm, 'use_stock_for_usage');
    }
}
