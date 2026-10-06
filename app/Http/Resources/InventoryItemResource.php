<?php

namespace App\Http\Resources;

use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockReasonCatalogue;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    private bool $withBalances = false;

    /** Include the per storage location / lot breakdown (single-item reads). */
    public function withBalances(): static
    {
        $this->withBalances = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $ledger = app(StockLedger::class);
        $total = Decimal::trim((string) ($this->stock_total ?? $ledger->itemBalance($this->id)));
        $threshold = $this->low_stock_threshold === null ? null : Decimal::trim((string) $this->low_stock_threshold);
        $out = [
            'id' => $this->id, 'name' => $this->name, 'category' => $this->category->value, 'description' => $this->description,
            /**
             * Stable semantic kind: decides which stock reasons apply (GET /master/inventory-options → by_item_kind[kind]). Never infer it from the name.
             *
             * @var 'feed'|'eggs'|'milk'|'general'
             */
            'kind' => StockReasonCatalogue::kindOf($this->resource),
            /** true for the farm's automatic Eggs / Milk output items (resolved by the system, `output: eggs|milk`); they cannot be mistaken for a farmer-defined item by name. */
            'is_system_managed' => $this->system_key !== null,
            'stock_unit' => $this->stockUnit->code, 'dimension' => $this->stockUnit->dimension->code,
            'tracks_lots' => $this->tracks_lots, 'tracks_expiry' => $this->tracks_expiry, 'is_active' => $this->is_active,
            /**
             * Derived from movements and never writable. `quantity` is in the item's stock_unit; `normalized` is the canonical unit (g, ml or the count unit).
             *
             * @var array{quantity: string, unit: string, normalized: array{quantity: string, unit: string}}
             */
            'stock' => ['quantity' => $ledger->display($this->resource, $total)['quantity'], 'unit' => $this->stockUnit->code, 'normalized' => $ledger->canonical($this->resource, $total)],
            /** @var array{quantity: string, unit: string}|null */
            'low_stock_threshold' => $threshold === null ? null : $ledger->display($this->resource, $threshold),
            'is_low_stock' => $threshold !== null && Decimal::cmp($total, $threshold) <= 0,
            'created_at' => $this->created_at->toISOString(), 'updated_at' => $this->updated_at->toISOString(),
        ];
        if ($this->withBalances) {
            /** @var array<int, array{storage_location_id: string, inventory_lot_id: string|null, quantity: string, unit: string, normalized: array{quantity: string, unit: string}}> */
            $out['balances'] = array_map(fn (array $row) => [
                'storage_location_id' => $row['storage_location_id'], 'inventory_lot_id' => $row['inventory_lot_id'],
                'quantity' => $ledger->display($this->resource, $row['canonical'])['quantity'], 'unit' => $this->stockUnit->code,
                'normalized' => $ledger->canonical($this->resource, $row['canonical']),
            ], $ledger->balances($this->id));
        }

        return $out;
    }
}
