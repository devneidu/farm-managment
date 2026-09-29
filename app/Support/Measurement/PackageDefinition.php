<?php

namespace App\Support\Measurement;

use App\Enums\ConversionContextType;

/**
 * "In this context, 1 <package unit> = <perPackage> <target unit>", as it stood when it was used. `conversionId` and
 * `version` identify the farm setting; the values themselves are what a snapshot relies on.
 */
final readonly class PackageDefinition
{
    public function __construct(
        public ?string $conversionId,
        public int $version,
        public ConversionContext $context,
        public UnitSpec $packageUnit,
        public UnitSpec $targetUnit,
        public string $perPackage,
    ) {}

    public function toArray(): array
    {
        return [
            'conversion_id' => $this->conversionId, 'version' => $this->version, 'context' => $this->context->toArray(),
            'package_unit' => $this->packageUnit->toArray(), 'target_unit' => $this->targetUnit->toArray(), 'per_package' => $this->perPackage,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['conversion_id'], $data['version'],
            new ConversionContext(ConversionContextType::from($data['context']['type']), $data['context']['id'] ?? $data['context']['key'], $data['context']['label']), // `key`: snapshots taken before contexts had ids
            UnitSpec::fromArray($data['package_unit']), UnitSpec::fromArray($data['target_unit']), $data['per_package'],
        );
    }
}
