<?php

namespace App\Http\Resources;

use App\Models\CropType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A crop type. Crop projects (later phase) are baselined by planting units; planting material and planting unit
 * type lists come from GET /master/planting-reference and are separate concepts.
 *
 * @property CropType $resource
 */
class CropTypeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, code: string, name: string, is_active: bool,
     *     operation: array{id: string, code: string, name: string, category: string, tracking_model: string}
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'operation' => OperationTypeResource::summary($this->operationType),
        ];
    }
}
