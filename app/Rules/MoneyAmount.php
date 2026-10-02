<?php

namespace App\Rules;

use App\Support\Finance\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A positive amount of money with at most two decimals (string, int or JSON number; never rounded silently). */
class MoneyAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $amount = Money::parse($value);
        if ($amount === null) {
            $fail('The :attribute must be an amount with at most two decimal places.');
        } elseif (Money::isZero($amount)) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}
