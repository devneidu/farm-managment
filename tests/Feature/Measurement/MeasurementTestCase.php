<?php

namespace Tests\Feature\Measurement;

use App\Enums\ConversionContextType;
use App\Models\Farm;
use App\Models\MeasurementContext;
use App\Models\PackageConversion;
use App\Models\Unit;
use App\Support\Measurement\ConversionContext;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitSpec;
use Tests\Feature\Team\TeamTestCase;

/** Standard units are provisioned by migration, so every test starts with the measurement catalogue in place. */
abstract class MeasurementTestCase extends TeamTestCase
{
    protected function unit(string $code): Unit
    {
        return Unit::with('dimension')->where('code', $code)->firstOrFail();
    }

    protected function spec(string $code): UnitSpec
    {
        return $this->unit($code)->spec();
    }

    protected function qty(mixed $value, string $unit): Quantity
    {
        return Quantity::of($value, $this->spec($unit));
    }

    /** The farm's measurement context with this name, created on first use (test setup). */
    protected function measurementContext(Farm $farm, string $name, bool $active = true): MeasurementContext
    {
        return MeasurementContext::firstOrCreate(
            ['farm_id' => $farm->id, 'normalized_name' => MeasurementContext::normalizeName($name)],
            ['name' => $name, 'is_active' => $active],
        );
    }

    /** A farm package conversion created directly (test setup) in the custom context called $label. */
    protected function conversion(Farm $farm, string $label, string $package, string $target, string|int $perPackage, bool $active = true): PackageConversion
    {
        return PackageConversion::create([
            'farm_id' => $farm->id, 'context_type' => ConversionContextType::Custom, 'context_id' => $this->measurementContext($farm, $label)->id,
            'package_unit_id' => $this->unit($package)->id, 'target_unit_id' => $this->unit($target)->id,
            'quantity_per_package' => (string) $perPackage, 'version' => 1, 'is_active' => $active,
        ]);
    }

    /** The context object for an existing custom context of the farm (default: the test farm). */
    protected function context(string $label, ?Farm $farm = null): ConversionContext
    {
        $context = MeasurementContext::where('farm_id', ($farm ?? $this->farm)->id)->where('normalized_name', MeasurementContext::normalizeName($label))->firstOrFail();

        return new ConversionContext(ConversionContextType::Custom, $context->id, $context->name);
    }

    /** @return array{type: string, id: string} */
    protected function ctx(string $label, ?Farm $farm = null): array
    {
        return ['type' => 'custom', 'id' => $this->context($label, $farm)->id];
    }

    /** @param  list<array{0: string|int, 1: string}>  $parts */
    protected function parts(array $parts): array
    {
        return array_map(fn ($p) => ['quantity' => $p[0], 'unit' => $p[1]], $parts);
    }
}
