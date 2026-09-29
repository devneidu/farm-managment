<?php

namespace App\Support\Measurement;

use App\Support\Api\ApiHttpException;

/**
 * Stable, machine-readable measurement errors (rendered through the standard error envelope with `code` and `details`).
 * Details only ever contain unit codes / dimensions / context identity - nothing internal.
 */
class MeasurementException extends ApiHttpException
{
    public const INCOMPATIBLE_UNITS = 'incompatible_units';

    public const CONVERSION_NOT_CONFIGURED = 'conversion_not_configured';

    public const CONVERSION_CONTEXT_REQUIRED = 'conversion_context_required';

    public const AMBIGUOUS_CONVERSION = 'ambiguous_conversion';

    public const INVALID_QUANTITY = 'invalid_quantity';

    public const INVALID_CONVERSION_RATIO = 'invalid_conversion_ratio';

    public const UNKNOWN_UNIT = 'unknown_unit';

    public const UNIT_NOT_SELECTABLE = 'unit_not_selectable';

    public const CONVERSION_EXISTS = 'conversion_exists';

    public const UNIT_DIMENSION_MISMATCH = 'unit_dimension_mismatch';

    public const CONTEXT_EXISTS = 'measurement_context_exists';

    /** A unit was offered for a field that requires a different measurement dimension (kg for a volume field). */
    public static function unitDimensionMismatch(string $unit, string $requiredDimension): self
    {
        return new self(422, self::UNIT_DIMENSION_MISMATCH, "The unit [{$unit}] is not a {$requiredDimension} unit.", details: [
            'unit' => $unit, 'required_dimension' => $requiredDimension,
        ]);
    }

    public static function incompatibleUnits(UnitSpec $from, UnitSpec $to): self
    {
        $hint = $from->isPackage() || $to->isPackage()
            ? ' A package unit needs a farm package conversion for its context.'
            : '';

        return new self(422, self::INCOMPATIBLE_UNITS, "Cannot convert {$from->code} ({$from->dimension}) to {$to->code} ({$to->dimension}).{$hint}", details: [
            'from_unit' => $from->code, 'to_unit' => $to->code, 'from_dimension' => $from->dimension, 'to_dimension' => $to->dimension,
        ]);
    }

    public static function conversionNotConfigured(string $packageUnit, ?ConversionContext $context, bool $inactive = false): self
    {
        return new self(422, self::CONVERSION_NOT_CONFIGURED, $inactive
            ? "The {$packageUnit} conversion for this context is switched off."
            : "No conversion is configured for {$packageUnit} in this context.", details: [
                'unit' => $packageUnit, 'context' => $context?->toArray(), 'inactive' => $inactive,
            ]);
    }

    /** @param  list<array{type: string, id: string, label: string}>  $candidates */
    public static function contextRequired(string $packageUnit, array $candidates): self
    {
        $ambiguous = count($candidates) > 1;

        return new self(422, $ambiguous ? self::AMBIGUOUS_CONVERSION : self::CONVERSION_CONTEXT_REQUIRED, $ambiguous
            ? "{$packageUnit} means different things in different contexts; say which context applies."
            : "A context is required to convert {$packageUnit}.", details: ['unit' => $packageUnit, 'candidates' => $candidates]);
    }

    public static function invalidQuantity(string $message, ?string $unit = null, array $extra = []): self
    {
        return new self(422, self::INVALID_QUANTITY, $message, details: array_filter(['unit' => $unit] + $extra, fn ($v) => $v !== null));
    }

    public static function invalidRatio(string $message): self
    {
        return new self(422, self::INVALID_CONVERSION_RATIO, $message);
    }

    public static function unknownUnit(string $code): self
    {
        return new self(422, self::UNKNOWN_UNIT, "Unknown unit [{$code}].", details: ['unit' => $code]);
    }

    public static function unitNotSelectable(string $code): self
    {
        return new self(422, self::UNIT_NOT_SELECTABLE, "The unit [{$code}] can no longer be selected.", details: ['unit' => $code]);
    }
}
