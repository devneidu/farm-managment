<?php

namespace App\Http\Resources;

use App\Models\MeasurementContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm-defined measurement context. Use `id` as `context_id` (with `context_type=custom`) on package conversions and
 * normalization; `name` is display text and can change without affecting anything that refers to the `id`.
 *
 * @property MeasurementContext $resource
 */
class MeasurementContextResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: string, name: string, is_active: bool, created_at: string|null, updated_at: string|null} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
