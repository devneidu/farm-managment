<?php

namespace App\Support\Entitlements;

/**
 * A resolved numeric limit. Unlimited is explicit (`unlimited = true`, `value = null`); there is no
 * "huge number" convention. A missing/malformed limit resolves to a value of 0 (nothing allowed).
 */
final readonly class LimitValue
{
    private function __construct(public ?int $value, public bool $unlimited) {}

    public static function of(int $value): self
    {
        return new self(max(0, $value), false);
    }

    public static function unlimited(): self
    {
        return new self(null, true);
    }

    public static function none(): self
    {
        return new self(0, false);
    }

    /** Whether $additional more units fit given the current usage. */
    public function allows(int $usage, int $additional = 1): bool
    {
        return $this->unlimited || $usage + $additional <= $this->value;
    }

    /** Remaining capacity (never negative); null when unlimited. */
    public function remaining(int $usage): ?int
    {
        return $this->unlimited ? null : max(0, $this->value - $usage);
    }
}
