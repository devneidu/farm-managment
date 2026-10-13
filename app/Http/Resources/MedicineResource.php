<?php

namespace App\Http\Resources;

use App\Models\InventoryItem;
use App\Services\Health\HealthQueries;
use App\Services\Inventory\StockLedger;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;

/**
 * @mixin InventoryItem
 *
 * An inventory item seen as a medicine: the Phase 9 item and derived stock plus its withdrawal profile.
 */
class MedicineResource extends InventoryItemResource
{
    private ?string $lotTimezone = null;

    public function withLots(string $farmTimezone): static
    {
        $this->lotTimezone = $farmTimezone;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $out = parent::toArray($request);
        $profile = $this->resource->relationLoaded('medicineProfile') ? $this->resource->getRelation('medicineProfile') : null;
        /** @var array{default_withdrawal_days: int|null, notes: string|null} */
        $out['profile'] = ['default_withdrawal_days' => $profile?->default_withdrawal_days, 'notes' => $profile?->notes];
        /** antibiotic|vaccine|supplement|dewormer|other; null = not specified (medicines created before the type existed). */
        $out['medicine_type'] = $profile?->medicine_type?->value;
        $out['medicine_type_label'] = $profile?->medicine_type?->label();
        /**
         * Active package conversions of THIS medicine (one package holds `quantity_per_package` of the stock unit). Empty = quantities can only be entered in the stock unit.
         *
         * @var array<int, array{conversion_id: string, package_unit: string, package_unit_label: string, quantity_per_package: string, unit: string}>
         */
        $out['packaging'] = $this->resource->relationLoaded('medicinePackaging')
            ? $this->resource->getRelation('medicinePackaging')->map(fn ($c) => [
                'conversion_id' => $c->id, 'package_unit' => $c->packageUnit->code, 'package_unit_label' => $c->packageUnit->name,
                'quantity_per_package' => Decimal::trim((string) $c->quantity_per_package), 'unit' => $c->targetUnit->code,
            ])->all() : [];
        if ($this->lotTimezone !== null) {
            $ledger = app(StockLedger::class);
            /** @var array<int, array{id: string, code: string, expires_on: string|null, is_expired: bool, stock: array{quantity: string, unit: string}}> */
            $out['lots'] = array_map(fn ($lot) => [
                'id' => $lot->id, 'code' => $lot->code, 'expires_on' => $lot->expires_on?->toDateString(),
                'is_expired' => $lot->expires_on !== null && $lot->expires_on->toDateString() < now($this->lotTimezone)->toDateString(),
                'stock' => $ledger->display($this->resource, Decimal::trim((string) $lot->stock_total)),
            ], app(HealthQueries::class)->stockLots($this->resource));
        }

        return $out;
    }
}
