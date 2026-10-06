<?php

namespace App\Rules;

use App\Enums\StockInReason;
use App\Enums\StockOutReason;
use App\Services\Inventory\StockReasonCatalogue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A reason owned by another workflow (a sale, a feed_use record, an incubation project) is refused on the manual stock
 * endpoints, with a message that names the endpoint to use. This is what keeps one real-world event from being entered -
 * and decremented - twice.
 */
class ManualStockReason implements ValidationRule
{
    public function __construct(private readonly string $direction) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // the enum rule reports it
        }
        $route = $this->direction === 'in'
            ? (($case = StockInReason::tryFrom($value)) && $case->isSystemOnly() ? StockReasonCatalogue::inRoute($case) : null)
            : (($case = StockOutReason::tryFrom($value)) && $case->isSystemOnly() ? StockReasonCatalogue::outRoute($case) : null);
        if ($route !== null) {
            $fail('The reason "'.$value.'" is recorded through '.$route['method'].' '.$route['path'].($route['kind'] === 'record' ? ' (type '.$route['record_type'].')' : '').' so the event is entered once; it cannot be used on the manual endpoint.');
        }
    }
}
