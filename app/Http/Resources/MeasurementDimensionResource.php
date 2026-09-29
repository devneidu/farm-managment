<?php

namespace App\Http\Resources;

use App\Models\MeasurementDimension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A measurement dimension. Populate a unit dropdown with `GET /master/units?dimension={code}`.
 *
 * @property MeasurementDimension $resource
 */
class MeasurementDimensionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string, code: string, name: string, supports_preference: bool,
     *     canonical_unit: array{code: string, symbol: string}|null, unit_count: int, is_active: bool
     * }
     */
    public function toArray(Request $request): array
    {
        $canonical = $this->units->firstWhere('is_canonical', true);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'supports_preference' => $this->supports_preference,
            'canonical_unit' => $canonical ? ['code' => $canonical->code, 'symbol' => $canonical->symbol] : null,
            'unit_count' => $this->units->count(),
            'is_active' => $this->is_active,
        ];
    }
}
