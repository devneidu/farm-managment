<?php

namespace App\Http\Resources\Platform;

use App\Models\CustomRecordType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record type as the platform sees it: the definition and usage COUNTS. Never the records themselves.
 *
 * @mixin CustomRecordType
 */
class CustomRecordTypeAdminResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'source' => $this->source, 'status' => $this->status, 'applies_to' => $this->applies_to,
            'species' => $this->species_id ? ['id' => $this->species_id, 'name' => $this->species?->name] : null,
            'crop_type' => $this->crop_type_id ? ['id' => $this->crop_type_id, 'name' => $this->cropType?->name] : null,
            /** @var list<array<string, mixed>> */
            'fields' => $this->fields, 'version' => $this->version,
            /** The farm that made it (null for a public type). */
            'farm' => $this->farm ? ['id' => $this->farm->id, 'name' => $this->farm->name] : null,
            'copied_from_id' => $this->copied_from_id,
            /** Records of this type across all farms, and how many farms have at least one. */
            'records_count' => (int) ($this->records_count ?? 0), 'farms_using' => (int) ($this->farms_using ?? 0),
            /** Farm types copied from this one (a public type's adopters). */
            'copies_count' => (int) ($this->copies_count ?? 0),
            /** For a farm-made type: the public copy made from it, if any. */
            'public_copy_id' => $this->public_copy_id ?? null,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
