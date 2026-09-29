<?php

namespace App\Http\Resources;

use App\Models\CropVariety;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `source` is `system` (platform default, read-only) or `farm` (this farm's custom variety). A variety is always
 * optional: never require one merely because a crop has varieties.
 *
 * @property CropVariety $resource
 */
class CropVarietyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, crop_type_id: string, code: string|null, name: string, source: 'system'|'farm',
     *     is_active: bool, is_editable: bool, created_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crop_type_id' => $this->crop_type_id,
            'code' => $this->code,
            'name' => $this->name,
            'source' => $this->resource->source(),
            'is_active' => $this->is_active,
            'is_editable' => ! $this->resource->isSystem(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
