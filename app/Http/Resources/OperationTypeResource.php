<?php

namespace App\Http\Resources;

use App\Models\OperationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm operation / production type. `selected` = the farm explicitly chose it (GET|PUT /farm/operations);
 * `available` = it is usable by the farm right now (true for every operation while the farm has selected none).
 * Branch UI on `category` / `tracking_model`, never on `name`.
 *
 * @property OperationType $resource
 */
class OperationTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, code: string, name: string, category: 'livestock'|'aquaculture'|'crop',
     *     tracking_model: 'population'|'planting_units', is_active: bool, selected: bool, available: bool
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category->value,
            'tracking_model' => $this->tracking_model->value,
            'is_active' => $this->is_active,
            'selected' => (bool) ($this->resource->getAttribute('selected') ?? false),
            'available' => (bool) ($this->resource->getAttribute('available') ?? true),
        ];
    }

    /** @return array{id: string, code: string, name: string, category: string, tracking_model: string} */
    public static function summary(OperationType $operation): array
    {
        return [
            'id' => $operation->id,
            'code' => $operation->code,
            'name' => $operation->name,
            'category' => $operation->category->value,
            'tracking_model' => $operation->tracking_model->value,
        ];
    }
}
