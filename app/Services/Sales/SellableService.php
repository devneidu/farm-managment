<?php

namespace App\Services\Sales;

use App\Enums\CycleKind;
use App\Enums\CycleStatus;
use App\Enums\InventoryCategory;
use App\Enums\Permission;
use App\Models\InventoryItem;
use App\Models\ProductionCycle;
use App\Models\StorageLocation;
use App\Services\Inventory\InventoryQueries;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockReasonCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Measurement\Decimal;

/**
 * What the farm can sell right now, for the "what did you sell?" list of the Sell form. Read-only: availability comes from the same
 * ledger sums the rest of the API uses (population movements for animals, inventory movements for stock), never from a stored balance.
 */
class SellableService
{
    public function __construct(private StockLedger $ledger) {}

    /** @return array{animals: list<array<string, mixed>>, in_store: list<array<string, mixed>>} */
    public function list(FarmContext $ctx): array
    {
        $ctx->authorize(Permission::SaleCreate);

        return ['animals' => $this->animals($ctx), 'in_store' => $this->inStore($ctx)];
    }

    /** Open livestock cycles with at least one head. */
    private function animals(FarmContext $ctx): array
    {
        return ProductionCycle::ofFarm($ctx->farm)->where('kind', CycleKind::Livestock->value)->where('status', CycleStatus::Active->value)
            ->with('livestock.species')->withSum('movements as current_population', 'quantity')
            ->orderBy('name')->orderBy('id')->get()
            ->filter(fn (ProductionCycle $cycle) => (int) $cycle->current_population > 0)
            ->map(fn (ProductionCycle $cycle) => [
                'production_cycle_id' => $cycle->id, 'reference' => $cycle->reference, 'label' => $cycle->name,
                'species' => $cycle->livestock?->species?->name, 'head_count' => (int) $cycle->current_population, 'unit' => 'head',
            ])->values()->all();
    }

    /** Sellable-category items with stock on hand, with where it is held (a sale line needs a storage location unless it names eggs/milk). */
    private function inStore(FarmContext $ctx): array
    {
        $items = InventoryItem::ofFarm($ctx->farm)->where('is_active', true)
            ->whereIn('category', array_map(fn (InventoryCategory $c) => $c->value, InventoryCategory::sellable()))
            ->with('stockUnit')->selectRaw('inventory_items.*, '.InventoryQueries::ITEM_TOTAL.' as stock_total')
            ->orderBy('normalized_name')->orderBy('id')->get()
            ->filter(fn (InventoryItem $item) => Decimal::cmp(Decimal::trim((string) $item->stock_total), '0') > 0);
        $places = StorageLocation::where('farm_id', $ctx->farm->id)->whereIn('id', $items->flatMap(fn ($item) => array_column($this->ledger->balances($item->id), 'storage_location_id'))->unique()->all())->pluck('name', 'id');

        return $items->map(fn (InventoryItem $item) => [
            'inventory_item_id' => $item->id,
            /** eggs | milk for the farm's automatic stock (sell it with output instead of inventory_item_id), otherwise null. */
            'output' => array_search($item->system_key, StockReasonCatalogue::OUTPUT_KEYS, true) ?: null,
            'label' => $item->name, 'category' => $item->category->value,
            'available' => $this->ledger->display($item, Decimal::trim((string) $item->stock_total)),
            'locations' => collect($this->ledger->balances($item->id))->filter(fn ($b) => Decimal::cmp($b['canonical'], '0') > 0)->map(fn ($b) => [
                'storage_location_id' => $b['storage_location_id'], 'storage_location' => $places[$b['storage_location_id']] ?? null,
                'lot_id' => $b['inventory_lot_id'], 'available' => $this->ledger->display($item, $b['canonical']),
            ])->values()->all(),
        ])->values()->all();
    }
}
