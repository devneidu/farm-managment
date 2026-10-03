<?php

namespace App\Support\Finance;

/**
 * Money is a numeric STRING with exactly two decimals ("1500.50"), added and compared with bcmath. PHP floats never touch an
 * amount: a JSON float is accepted only through its shortest exact text and refused if it carries more than two decimals.
 */
final class Money
{
    public const SCALE = 2;

    public static function parse(mixed $value): ?string
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/^(\d{1,16})(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', self::SCALE);
    }

    /** Display text with thousands separators ("1,234,567.50"); string arithmetic only, never a float. */
    public static function format(string $amount): string
    {
        $amount = bcadd($amount, '0', self::SCALE);
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = explode('.', ltrim($amount, '-'));

        return ($negative ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /** @param  iterable<string>  $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $amount) {
            $total = self::add($total, $amount);
        }

        return $total;
    }
}
