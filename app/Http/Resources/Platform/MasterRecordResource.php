<?php

namespace App\Http\Resources\Platform;

use App\Models\Breed;
use App\Models\Species;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One platform reference record (operation type, species, crop type, system breed/variety or planting reference value). Identity is `code`
 * (permanent); parent and category are shown but cannot be edited. Fields that do not apply to the kind are omitted.
 *
 * @property Model $resource
 */
class MasterRecordResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $has = fn (string $attribute) => array_key_exists($attribute, $r->getAttributes());
        $ref = fn ($rel) => $rel ? ['id' => $rel->id, 'code' => $rel->code, 'name' => $rel->name] : null;

        $out = ['id' => $r->id, 'code' => $r->code, 'name' => $r->name];
        if ($has('category')) {
            $out += ['category' => $r->category->value, 'tracking_model' => $r->tracking_model->value];
        }
        if ($has('livestock_group')) {
            $out['livestock_group'] = $r->livestock_group?->value;
        }
        if ($has('list')) {
            $out['list'] = $r->list;
        }
        foreach ([['operation_type_id', 'operation_type', 'operationType'], ['species_id', 'species', 'species'], ['crop_type_id', 'crop_type', 'cropType']] as [$column, $key, $relation]) {
            if ($has($column)) {
                $out[$key] = $ref($r->{$relation});
            }
        }
        if ($has('sort_order')) {
            $out['sort_order'] = $r->sort_order;
        }

        return $out + ['is_active' => $r->is_active, 'created_at' => $r->created_at?->toIso8601String(), 'updated_at' => $r->updated_at?->toIso8601String()];
    }
}
