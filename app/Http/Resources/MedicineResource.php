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
