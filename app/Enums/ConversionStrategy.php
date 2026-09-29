<?php

namespace App\Enums;

/**
 * How a standard unit converts to its family's canonical unit. Deliberately a closed set of known strategies - there
 * is no formula language. Multiplicative units use `linear`; temperature needs an offset, so it has its own strategy.
 */
enum ConversionStrategy: string
{
    case Linear = 'linear';         // canonical = value * to_canonical_factor
    case Fahrenheit = 'fahrenheit'; // canonical(Celsius) = (value - 32) * 5 / 9
    case None = 'none';             // package units: no standard conversion exists
}
