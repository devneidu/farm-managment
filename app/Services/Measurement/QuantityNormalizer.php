<?php

namespace App\Services\Measurement;

use App\Models\Farm;
use App\Support\Measurement\ConversionContext;
use App\Support\Measurement\MeasurementException;
use App\Support\Measurement\NormalizationResult;
use App\Support\Measurement\Quantity;

/**
 * The entry point later operational modules (feed, eggs, harvest, ...) use to turn ENTERED quantities into normalized
 * ones for a farm: it resolves unit codes, requires an explicit context for package units, loads that farm's package
 * definitions, and delegates all arithmetic to MeasurementConverter. It never guesses which package definition applies.
 *
 * Optional `$requiredDimensions` lets a domain field say what it accepts (water: ['volume']; medicine dose:
 * ['weight', 'volume']); parts and the result unit outside them are rejected with `unit_dimension_mismatch`.
 * Package parts are exempt because they resolve to their configured target unit, which is then checked.
 */
class QuantityNormalizer
{
    public function __construct(
        private readonly UnitCatalogue $units,
        private readonly PackageConversionService $packages,
        private readonly MeasurementConverter $converter,
    ) {}

    /**
     * @param  list<array{quantity: mixed, unit: string}>  $parts  e.g. [['quantity' => '3', 'unit' => 'crate'], ['quantity' => '14', 'unit' => 'piece']]
     * @param  list<string>|null  $requiredDimensions
     */
    public function normalize(Farm $farm, array $parts, ?ConversionContext $context = null, ?string $resultUnit = null, ?array $requiredDimensions = null): NormalizationResult
    {
        $quantities = [];
        $packageUnits = [];

        foreach ($parts as $part) {
            $unit = $this->units->selectable((string) $part['unit']);
            $quantities[] = Quantity::of($part['quantity'], $unit->spec());
            if ($unit->dimension->code === 'package') {
                $packageUnits[] = $unit;
            } elseif ($requiredDimensions !== null) {
                $this->assertDimension($unit->code, $unit->dimension->code, $requiredDimensions);
            }
        }

        $definitions = [];
        if ($packageUnits !== []) {
            if ($context === null) {
                throw MeasurementException::contextRequired($packageUnits[0]->code, $this->packages->candidateContexts($farm, $packageUnits[0]));
            }
            // Never trust the caller's context: it must be a real, active context this farm may use (label comes from the entity).
            $context = $this->packages->resolveContext($farm, $context->type, $context->id);
            $definitions = $this->packages->definitionsFor($farm, $context, $packageUnits);
            if ($requiredDimensions !== null) {
                foreach ($definitions as $definition) {
                    $this->assertDimension($definition->targetUnit->code, $definition->targetUnit->dimension, $requiredDimensions);
                }
            }
        }

        $resultSpec = null;
        if ($resultUnit !== null) {
            $result = $this->units->selectable($resultUnit);
            if ($requiredDimensions !== null) {
                $this->assertDimension($result->code, $result->dimension->code, $requiredDimensions);
            }
            $resultSpec = $result->spec();
        }

        return $this->converter->normalize($quantities, $definitions, $resultSpec);
    }

    /** @param  list<string>  $required */
    private function assertDimension(string $unit, string $dimension, array $required): void
    {
        if (! in_array($dimension, $required, true)) {
            throw MeasurementException::unitDimensionMismatch($unit, implode(' or ', $required));
        }
    }
}
