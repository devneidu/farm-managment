<?php

namespace App\Http\Resources;

use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedFormulaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'species_id' => $this->species_id, 'description' => $this->description,
            'version' => $this->version, 'is_active' => $this->is_active,
            'items' => $this->items->map(fn ($i) => ['ingredient_name' => $i->ingredient_name, 'inclusion_percent' => Decimal::trim((string) $i->inclusion_percent), 'inventory_item_id' => $i->inventory_item_id])->all(),
            'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
