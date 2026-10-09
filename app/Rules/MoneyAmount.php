<?php

namespace App\Rules;

use App\Support\Finance\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Money with at most two decimals, positive by default; explicitly allow zero for informational acquisition prices. */
class MoneyAmount implements ValidationRule
{
    public function __construct(private readonly bool $allowZero = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $amount = Money::parse($value);
        if ($amount === null) {
            $fail('The :attribute must be an amount with at most two decimal places.');
        } elseif (! $this->allowZero && Money::isZero($amount)) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}
