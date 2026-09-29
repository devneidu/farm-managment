<?php

namespace App\Http\Resources;

use App\Models\Breed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `source` is `system` (platform default, read-only) or `farm` (this farm's custom breed, editable with
 * `master_data.manage`). `code` is set for system breeds only. Inactive breeds are not selectable for new records
 * but remain resolvable for history.
 *
 * @property Breed $resource
 */
class BreedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, species_id: string, code: string|null, name: string, source: 'system'|'farm',
     *     is_active: bool, is_editable: bool, created_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'species_id' => $this->species_id,
            'code' => $this->code,
            'name' => $this->name,
            'source' => $this->resource->source(),
            'is_active' => $this->is_active,
            'is_editable' => ! $this->resource->isSystem(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
