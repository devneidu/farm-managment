<?php

namespace App\Http\Resources;

use App\Models\CustomRecordType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A farm-made or public record type. `records_count` is THIS farm's records of the type; when it is above 0 the type is `in_use` and each field
 * is `locked` (it can be hidden or relabelled, but not deleted or changed to another kind). `can_edit` is false for public types.
 *
 * @mixin CustomRecordType
 */
class CustomRecordTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $count = (int) ($this->records_count ?? 0);

        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'source' => $this->source, 'status' => $this->status,
            'applies_to' => $this->applies_to,
            'species' => $this->species_id ? ['id' => $this->species_id, 'name' => $this->species?->name] : null,
            'crop_type' => $this->crop_type_id ? ['id' => $this->crop_type_id, 'name' => $this->cropType?->name] : null,
            /** @var list<array{key: string, label: string, kind: string, required: bool, hidden: bool, unit?: string|null, min?: string|null, max?: string|null, options?: list<string>, locked: bool}> */
            'fields' => array_map(fn ($f) => $f + ['locked' => $count > 0], $this->fields),
            'version' => $this->version, 'copied_from_id' => $this->copied_from_id,
            'in_use' => $count > 0, 'records_count' => $count, 'can_edit' => $this->farm_id !== null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
