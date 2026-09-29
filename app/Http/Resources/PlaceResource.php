<?php

namespace App\Http\Resources;

use App\Models\Place;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property Place $resource */
class PlaceResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: string, kind: string, name: string, type: string, type_label: string, parent_id: string|null, path: list<array{id: string, kind: string, name: string}>, path_label: string, depth: int, is_active: bool, created_at: string|null, updated_at: string|null} */
    public function toArray(Request $request): array
    {
        $path = [];
        for ($node = $this->resource; $node !== null; $node = $node->parent) {
            array_unshift($path, ['id' => $node->id, 'kind' => $node->kind()->value, 'name' => $node->name]);
        }

        return [
            'id' => $this->id,
            'kind' => $this->resource->kind()->value,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->resource->kind()->types()[$this->type],
            'parent_id' => $this->resource->{$this->resource->kind()->parentColumn()},
            'path' => $path,
            'path_label' => implode(' / ', array_column($path, 'name')),
            'depth' => count($path) - 1,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
