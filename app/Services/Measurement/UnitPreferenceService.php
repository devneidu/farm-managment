<?php

namespace App\Services\Measurement;

use App\Models\Farm;
use App\Models\FarmUnitPreference;
use App\Models\MeasurementDimension;
use App\Models\Unit;
use App\Support\Measurement\MeasurementException;
use Illuminate\Support\Facades\DB;

/**
 * A farm's preferred DISPLAY/ENTRY unit per measurement dimension (weight, volume, area, temperature). Preferences
 * never touch stored quantities - those are always kept in canonical units with their entered representation.
 * Farm-wide (one row per farm+dimension), per docs/implementations/03-ERD.md (`farm_unit_preferences`).
 */
class UnitPreferenceService
{
    /** Used while a farm has chosen nothing: Nigeria-first defaults. */
    public const DEFAULTS = ['weight' => 'kg', 'volume' => 'l', 'area' => 'hectare', 'temperature' => 'celsius'];

    public function __construct(private readonly UnitCatalogue $catalogue) {}

    /**
     * One entry per preference-enabled dimension: the farm's unit, or the default (`is_default` true).
     *
     * @return list<array{dimension: MeasurementDimension, unit: Unit|null, is_default: bool}>
     */
    public function forFarm(Farm $farm): array
    {
        $chosen = FarmUnitPreference::with('unit.dimension')->where('farm_id', $farm->id)->get()->keyBy('dimension_id');

        return MeasurementDimension::where('supports_preference', true)->where('is_active', true)->ordered()->get()
            ->map(function (MeasurementDimension $dimension) use ($chosen) {
                $preference = $chosen->get($dimension->id);
                $unit = $preference?->unit ?? Unit::with('dimension')->where('code', self::DEFAULTS[$dimension->code] ?? '')->first();

                return ['dimension' => $dimension, 'unit' => $unit, 'is_default' => $preference === null];
            })->all();
    }

    /**
     * Applies a partial change: `dimension code => unit code` sets it, `null` resets that dimension to the default;
     * dimensions not mentioned are untouched.
     *
     * @param  array<string, string|null>  $changes
     */
    public function update(Farm $farm, array $changes): void
    {
        $resolved = [];
        foreach ($changes as $dimensionCode => $unitCode) {
            $dimension = MeasurementDimension::where('code', $dimensionCode)->where('supports_preference', true)->where('is_active', true)->first()
                ?? throw MeasurementException::unknownUnit((string) $dimensionCode);
            $unit = null;
            if ($unitCode !== null) {
                $unit = $this->catalogue->selectable($unitCode);
                if ($unit->dimension_id !== $dimension->id) {
                    throw MeasurementException::unitDimensionMismatch($unit->code, $dimension->code);
                }
            }
            $resolved[] = [$dimension, $unit];
        }

        DB::transaction(function () use ($farm, $resolved) {
            foreach ($resolved as [$dimension, $unit]) {
                if ($unit === null) {
                    FarmUnitPreference::where('farm_id', $farm->id)->where('dimension_id', $dimension->id)->delete();
                } else {
                    FarmUnitPreference::updateOrCreate(['farm_id' => $farm->id, 'dimension_id' => $dimension->id], ['unit_id' => $unit->id]);
                }
            }
        });
    }
}
