<?php

namespace App\Services\Measurement;

use App\Enums\ConversionStrategy;
use App\Support\Measurement\Decimal;
use App\Support\Measurement\MeasurementException;
use App\Support\Measurement\NormalizationResult;
use App\Support\Measurement\PackageDefinition;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitSpec;

/**
 * THE conversion/normalization engine. Pure: it works on UnitSpecs and PackageDefinitions only (no database, no farm),
 * so the same code serves the API preview, later operational modules, and snapshot replay. Nothing else in the
 * application should multiply or divide quantities by unit factors.
 *
 *  - Standard conversion: same non-null unit `family` only, through the family's canonical unit (linear factor, or the
 *    known Fahrenheit strategy). Everything else is `incompatible_units`.
 *  - Package units (bag, crate, ...) never convert on their own: a PackageDefinition for the context must be supplied,
 *    which turns "N packages" into a quantity of its target unit (kg, pieces, ...).
 *  - Compound quantities (3 crates + 14 pieces) are summed in the canonical unit.
 */
class MeasurementConverter
{
    public const SNAPSHOT_SCHEMA = 1;

    /** family => code of its canonical unit (mirrors MeasurementSeeder; count families are their own canonical unit). */
    private const CANONICAL_CODES = ['weight' => 'g', 'volume' => 'ml', 'area' => 'sq_m', 'temperature' => 'celsius'];

    /** Standard same-family conversion of a single quantity. */
    public function convert(Quantity $quantity, UnitSpec $to): Quantity
    {
        if ($quantity->unit->isPackage() || $to->isPackage()) {
            throw MeasurementException::incompatibleUnits($quantity->unit, $to);
        }

        return $this->normalize([$quantity], [], $to)->total;
    }

    /**
     * @param  list<Quantity>  $components  entered parts, e.g. [3 crate, 14 piece]
     * @param  array<string, PackageDefinition>  $packages  definitions in force for the context, keyed by package unit code
     * @param  UnitSpec|null  $resultUnit  unit for `total` (default: the target of the first package part, else the first part's unit)
     */
    public function normalize(array $components, array $packages = [], ?UnitSpec $resultUnit = null): NormalizationResult
    {
        if ($components === []) {
            throw MeasurementException::invalidQuantity('At least one quantity is required.');
        }

        $codes = array_map(fn (Quantity $q) => $q->unit->code, $components);
        if (count($codes) !== count(array_unique($codes))) {
            throw MeasurementException::invalidQuantity('Each unit may appear only once in a compound quantity.');
        }

        $family = null;
        $familyUnit = null;
        $canonicalSum = '0';
        $usedPackages = [];
        $naturalUnit = null;

        foreach ($components as $part) {
            $value = $part->value;
            $unit = $part->unit;

            if ($unit->isPackage()) {
                $definition = $packages[$unit->code] ?? throw MeasurementException::conversionNotConfigured($unit->code, null);
                $value = Decimal::mul($value, $definition->perPackage);
                $unit = $definition->targetUnit;
                $usedPackages[] = $definition;
                $naturalUnit ??= $unit;
            }

            if ($unit->family === null) {
                throw MeasurementException::incompatibleUnits($part->unit, $unit);
            }
            if ($unit->integerOnly && ! Decimal::isInteger($value)) {
                throw MeasurementException::invalidQuantity("{$unit->code} is counted in whole numbers; the result would be a fraction.", $unit->code, ['reason' => 'fraction_not_allowed']);
            }
            if ($family !== null && $family !== $unit->family) {
                throw MeasurementException::incompatibleUnits($familyUnit, $unit);
            }
            $family = $unit->family;
            $familyUnit ??= $unit;

            $canonicalSum = Decimal::add($canonicalSum, $this->toCanonical($value, $unit));
        }

        if ($familyUnit->isTemperature() && count($components) > 1) {
            throw MeasurementException::invalidQuantity('Temperatures cannot be added together.');
        }

        $resultUnit ??= $naturalUnit ?? $components[0]->unit;
        if ($resultUnit->family === null || $resultUnit->family !== $family) {
            throw MeasurementException::incompatibleUnits($familyUnit, $resultUnit);
        }

        $total = $this->fromCanonical($canonicalSum, $resultUnit);
        if ($resultUnit->integerOnly && ! Decimal::isInteger($total)) {
            throw MeasurementException::invalidQuantity("{$resultUnit->code} is counted in whole numbers; the result would be a fraction.", $resultUnit->code, ['reason' => 'fraction_not_allowed']);
        }
        if (Decimal::integerDigits($canonicalSum) > Decimal::MAX_NORMALIZED_INTEGER_DIGITS || Decimal::integerDigits($total) > Decimal::MAX_NORMALIZED_INTEGER_DIGITS) {
            throw MeasurementException::invalidQuantity('The quantity is too large.');
        }

        $canonicalUnit = $this->canonicalOf($familyUnit);
        $normalized = new Quantity(Decimal::round($canonicalSum), $canonicalUnit);
        $totalQuantity = new Quantity($total, $resultUnit);

        return new NormalizationResult($components, $normalized, $totalQuantity, [
            'schema' => self::SNAPSHOT_SCHEMA,
            'entered' => array_map(fn (Quantity $q) => ['quantity' => $q->value, 'unit' => $q->unit->toArray()], $components),
            'packages' => array_map(fn (PackageDefinition $d) => $d->toArray(), $usedPackages),
            'result_unit' => $resultUnit->toArray(),
            'normalized' => $normalized->toArray(),
            'total' => $totalQuantity->toArray(),
        ]);
    }

    /** Recomputes a stored snapshot from its own contents (no database). Used to prove/explain historical quantities. */
    public function replay(array $snapshot): NormalizationResult
    {
        $components = array_map(fn (array $e) => new Quantity($e['quantity'], UnitSpec::fromArray($e['unit'])), $snapshot['entered']);
        $packages = [];
        foreach ($snapshot['packages'] as $raw) {
            $definition = PackageDefinition::fromArray($raw);
            $packages[$definition->packageUnit->code] = $definition;
        }

        return $this->normalize($components, $packages, UnitSpec::fromArray($snapshot['result_unit']));
    }

    /**
     * The canonical unit of a family, as a spec. Canonical units have factor 1 and the linear strategy, so a spec is
     * synthesised from the family rather than read from the database (keeps the converter pure).
     */
    private function canonicalOf(UnitSpec $member): UnitSpec
    {
        return new UnitSpec(self::CANONICAL_CODES[$member->family] ?? $member->family, $member->dimension, $member->family, ConversionStrategy::Linear, '1', $member->integerOnly);
    }

    private function toCanonical(string $value, UnitSpec $unit): string
    {
        return match ($unit->strategy) {
            ConversionStrategy::Linear => Decimal::mul($value, $unit->factor),
            // (F - 32) x 5 / 9 - multiply first so the only inexact step is the final division
            ConversionStrategy::Fahrenheit => Decimal::div(Decimal::mul(Decimal::sub($value, '32'), '5'), '9'),
            ConversionStrategy::None => throw MeasurementException::incompatibleUnits($unit, $unit),
        };
    }

    private function fromCanonical(string $canonical, UnitSpec $unit): string
    {
        $value = match ($unit->strategy) {
            ConversionStrategy::Linear => Decimal::div($canonical, $unit->factor),
            ConversionStrategy::Fahrenheit => Decimal::add(Decimal::div(Decimal::mul($canonical, '9'), '5'), '32'),
            ConversionStrategy::None => throw MeasurementException::incompatibleUnits($unit, $unit),
        };

        return Decimal::round($value);
    }
}
