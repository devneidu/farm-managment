<?php

namespace App\Http\Requests\Measurement\Concerns;

use Closure;

/**
 * Quantities travel as decimal strings (preferred) or numbers. Exactness is enforced by the domain layer
 * (`invalid_quantity` / `invalid_conversion_ratio`); this only rejects arrays, booleans and null.
 */
trait ScalarQuantityRule
{
    protected function scalarNumber(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! (is_string($value) || is_int($value) || is_float($value))) {
                $fail('The :attribute must be a number.');
            }
        };
    }
}
