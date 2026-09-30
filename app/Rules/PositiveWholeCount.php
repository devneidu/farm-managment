<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A JSON boolean or floating-point value is not a discrete baseline count. */
class PositiveWholeCount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]{0,11}$/D', (string) $value)) {
            $fail('The :attribute must be a positive whole-number count of at most 12 digits.');
        }
    }
}
