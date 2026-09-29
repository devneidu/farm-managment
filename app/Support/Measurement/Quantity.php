<?php

namespace App\Support\Measurement;

/** An exact decimal amount in a specific unit. Construct with {@see Quantity::of()} (validates) or internally via the converter. */
final readonly class Quantity
{
    public function __construct(public string $value, public UnitSpec $unit) {}

    /**
     * Validates an ENTERED quantity: exact decimal (12 integer digits, 6 decimals), not negative (except temperature),
     * and whole when the unit is discrete (heads, eggs).
     */
    public static function of(mixed $value, UnitSpec $unit): self
    {
        $parsed = Decimal::parse($value);
        if ($parsed === null) {
            throw MeasurementException::invalidQuantity('The quantity must be a decimal number with at most 12 digits before and 6 after the decimal point.', $unit->code);
        }
        if (Decimal::isNegative($parsed) && ! $unit->isTemperature()) {
            throw MeasurementException::invalidQuantity('The quantity cannot be negative.', $unit->code);
        }
        if ($unit->integerOnly && ! Decimal::isInteger($parsed)) {
            throw MeasurementException::invalidQuantity("{$unit->code} is counted in whole numbers; fractions are not allowed.", $unit->code, ['reason' => 'fraction_not_allowed']);
        }

        return new self($parsed, $unit);
    }

    /** @return array{quantity: string, unit: string} */
    public function toArray(): array
    {
        return ['quantity' => $this->value, 'unit' => $this->unit->code];
    }
}
