<?php

namespace App\Http\Resources;

use App\Models\PackageConversion;
use App\Models\Unit;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * "In `context`, 1 `package_unit` = `quantity_per_package` `target_unit`" - for THIS farm only. `context.id` is the
 * durable identity; `context.label` is its current display name. `version` increases whenever the quantity, target
 * unit or active state changes.
 *
 * @property PackageConversion $resource
 */
class PackageConversionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{
     *     id: string,
     *     context: array{type: 'crop_type'|'custom', id: string, label: string},
     *     package_unit: array{code: string, name: string, symbol: string},
     *     target_unit: array{code: string, name: string, symbol: string, dimension: string},
     *     quantity_per_package: string, version: int, is_active: bool, created_at: string|null, updated_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'context' => $this->conversionContext()->toArray(),
            'package_unit' => $this->unit($this->packageUnit),
            'target_unit' => $this->unit($this->targetUnit) + ['dimension' => $this->targetUnit->dimension->code],
            'quantity_per_package' => Decimal::trim((string) $this->quantity_per_package),
            'version' => $this->version,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function unit(Unit $unit): array
    {
        return ['code' => $unit->code, 'name' => $unit->name, 'symbol' => $unit->symbol];
    }
}
