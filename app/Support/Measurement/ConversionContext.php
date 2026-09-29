<?php

namespace App\Support\Measurement;

use App\Enums\ConversionContextType;

/**
 * Identity of "what is being packaged": (type, id) - for example (crop_type, <maize id>) or (custom, <measurement context
 * id>); later (inventory_item, <item id>). `id` is the identity; `label` is display text as it was when this was built.
 */
final readonly class ConversionContext
{
    public function __construct(public ConversionContextType $type, public string $id, public ?string $label = null) {}

    /** @return array{type: string, id: string, label: string|null} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'id' => $this->id, 'label' => $this->label];
    }
}
