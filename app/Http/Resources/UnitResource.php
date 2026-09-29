<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A unit of measurement. Match on `code`, never on `name`/`symbol`. `is_active` = selectable for NEW entries (inactive
 * units are only returned with `include_inactive=true`, to display historical values).
 *
 * @property Unit $resource
 */
class UnitResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, code: string, name: string, symbol: string, dimension: string, family: string|null,
     *     is_canonical: bool, integer_only: bool, decimal_places: int, is_active: bool, source: 'system'|'custom'
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'dimension' => $this->dimension->code,
            'family' => $this->family,
            'is_canonical' => $this->is_canonical,
            'integer_only' => $this->integer_only,
            'decimal_places' => $this->decimal_places,
            'is_active' => $this->is_active,
            'source' => $this->is_system ? 'system' : 'custom',
        ];
    }
}
