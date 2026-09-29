<?php

namespace App\Support\Measurement;

/**
 * Outcome of normalizing an entered (possibly compound) quantity.
 *
 * - `entered`     what the user typed, preserved exactly (3 crates + 14 pieces);
 * - `normalized`  the total in the family's canonical unit (the value to store and compute with);
 * - `total`       the same total expressed in a human-friendly unit (104 pieces / 618 kg);
 * - `snapshot`    self-contained record of every conversion used - persist it beside the quantity. It replays to the
 *                 same numbers via MeasurementConverter::replay() even if units or farm settings change later.
 */
final readonly class NormalizationResult
{
    /** @param  list<Quantity>  $entered */
    public function __construct(
        public array $entered,
        public Quantity $normalized,
        public Quantity $total,
        public array $snapshot,
    ) {}

    public function toArray(): array
    {
        return [
            'entered' => array_map(fn (Quantity $q) => $q->toArray(), $this->entered),
            'normalized' => $this->normalized->toArray(),
            'total' => $this->total->toArray(),
            'snapshot' => $this->snapshot,
        ];
    }
}
