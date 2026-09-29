<?php

namespace App\Support\Measurement;

use App\Enums\ConversionStrategy;

/**
 * Immutable description of a unit's conversion behaviour. The converter only ever sees UnitSpecs, and conversion
 * snapshots embed them - which is what makes a stored snapshot replayable without any database row.
 */
final readonly class UnitSpec
{
    public function __construct(
        public string $code,
        public string $dimension,
        public ?string $family,
        public ConversionStrategy $strategy,
        public ?string $factor,
        public bool $integerOnly,
    ) {}

    public function isPackage(): bool
    {
        return $this->strategy === ConversionStrategy::None;
    }

    public function isTemperature(): bool
    {
        return $this->dimension === 'temperature';
    }

    /** @return array{code: string, dimension: string, family: string|null, strategy: string, factor: string|null, integer_only: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code, 'dimension' => $this->dimension, 'family' => $this->family,
            'strategy' => $this->strategy->value, 'factor' => $this->factor, 'integer_only' => $this->integerOnly,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['code'], $data['dimension'], $data['family'], ConversionStrategy::from($data['strategy']), $data['factor'], (bool) $data['integer_only']);
    }
}
