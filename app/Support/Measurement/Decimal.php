<?php

namespace App\Support\Measurement;

/**
 * Exact decimal arithmetic on numeric STRINGS (bcmath). No PHP float ever touches an authoritative quantity.
 *
 * Limits (also the storage contract for later modules' quantity columns, `decimal(24,6)`):
 *  - an ENTERED quantity has at most 12 integer digits and 6 decimals;
 *  - a NORMALIZED quantity has at most 18 integer digits (12 digits x the largest unit factor, 10^6) and 6 decimals;
 *  - calculations run at 18 decimals and results are rounded half away from zero to 6 decimals.
 */
final class Decimal
{
    public const SCALE = 6;

    public const CALC_SCALE = 18;

    public const MAX_ENTERED_INTEGER_DIGITS = 12;

    public const MAX_NORMALIZED_INTEGER_DIGITS = 18;

    /**
     * Validates an entered decimal (string or int; a JSON float is accepted through its shortest exact text) and returns
     * it normalised ("30.500" -> "30.5", "-0" -> "0"), or null when malformed or over-precise / too large.
     */
    public static function parse(mixed $value, int $maxIntegerDigits = self::MAX_ENTERED_INTEGER_DIGITS): ?string
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/^-?(\d{1,'.$maxIntegerDigits.'})(\.\d{1,'.self::SCALE.'})?$/', $value)) {
            return null;
        }

        return self::trim($value);
    }

    /** Strips trailing zeros ("2.500" -> "2.5", "3.0" -> "3", "-0.00" -> "0"). */
    public static function trim(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return ($value === '' || $value === '-0' || $value === '-') ? '0' : $value;
    }

    public static function add(string $a, string $b): string
    {
        return self::trim(bcadd($a, $b, self::CALC_SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::trim(bcsub($a, $b, self::CALC_SCALE));
    }

    public static function mul(string $a, string $b): string
    {
        return self::trim(bcmul($a, $b, self::CALC_SCALE));
    }

    public static function div(string $a, string $b): string
    {
        return self::trim(bcdiv($a, $b, self::CALC_SCALE));
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::CALC_SCALE);
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isInteger(string $value): bool
    {
        return ! str_contains(self::trim($value), '.');
    }

    /** Number of digits before the decimal point (sign ignored). */
    public static function integerDigits(string $value): int
    {
        return strlen(ltrim(explode('.', self::trim($value))[0], '-'));
    }

    /** Rounds half away from zero to $places decimals (default: the storage scale). */
    public static function round(string $value, int $places = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $places).'5';
        $adjusted = self::isNegative($value)
            ? bcsub($value, $half, self::CALC_SCALE)
            : bcadd($value, $half, self::CALC_SCALE);

        return self::trim(bcadd($adjusted, '0', $places));
    }
}
