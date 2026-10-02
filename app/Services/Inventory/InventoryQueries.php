<?php

namespace App\Services\Inventory;

use App\Enums\Permission;
use App\Models\FeedFormula;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Read side: farm-scoped, view-permission-gated listings. Balances are always SUMs over the ledger. */
class InventoryQueries
{
    private const ITEM_TOTAL = '(select COALESCE(SUM(m.quantity_delta), 0) from inventory_movements m where m.inventory_item_id = inventory_items.id)';

    private const LOT_TOTAL = '(select COALESCE(SUM(m.quantity_delta), 0) from inventory_movements m where m.inventory_lot_id = inventory_lots.id)';

    public function item(FarmContext $ctx, string $id): InventoryItem
    {
        $ctx->authorize(Permission::InventoryView);

        return InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->findOrFail($id);
    }

    public function items(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::InventoryView);
        $q = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->selectRaw('inventory_items.*, '.self::ITEM_TOTAL.' as stock_total');
        if (! ($f['include_inactive'] ?? false)) {
            $q->where('is_active', true);
        }
        if (isset($f['category'])) {
            $q->where('category', $f['category']);
        }
        if (! empty($f['search'])) {
            $q->where('normalized_name', 'like', '%'.addcslashes(InventoryItem::normalizeName($f['search']), '%_\\').'%');
        }
        if (isset($f['low_stock'])) {
            $low = self::ITEM_TOTAL.' <= inventory_items.low_stock_threshold';
            filter_var($f['low_stock'], FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull('low_stock_threshold')->whereRaw($low)
                : $q->where(fn ($w) => $w->whereNull('low_stock_threshold')->orWhereRaw('NOT ('.$low.')'));
        }

        return $q->orderBy('normalized_name')->orderBy('id')->paginate($f['per_page'] ?? 50);
    }

    public function movements(FarmContext $ctx, array $f, ?string $itemId = null): LengthAwarePaginator
    {
        $ctx->authorize(Permission::InventoryView);
        $q = InventoryMovement::where('farm_id', $ctx->farm->id)->with(['item.stockUnit', 'lot', 'reversal']);
        if ($itemId !== null) {
            InventoryItem::ofFarm($ctx->farm)->findOrFail($itemId);
            $q->where('inventory_item_id', $itemId);
        }
        foreach (['inventory_item_id', 'storage_location_id', 'inventory_lot_id', 'operational_record_id', 'health_record_id', 'purchase_id'] as $column) {
            if (isset($f[$column])) {
                $q->where($column, $f[$column]);
            }
        }
        if (isset($f['type'])) {
            $q->where('type', $f['type']);
        }
        if (isset($f['recorded_from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($f['recorded_from'], $ctx->farm->timezone)->startOfDay()->utc());
        }
        if (isset($f['recorded_to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($f['recorded_to'], $ctx->farm->timezone)->addDay()->startOfDay()->utc());
        }

        return $q->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function lots(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::InventoryView);
        $today = CarbonImmutable::now($ctx->farm->timezone)->toDateString();
        $q = InventoryLot::where('farm_id', $ctx->farm->id)->with('item.stockUnit')->selectRaw('inventory_lots.*, '.self::LOT_TOTAL.' as stock_total');
        if (isset($f['inventory_item_id'])) {
            $q->where('inventory_item_id', $f['inventory_item_id']);
        }
        if (! ($f['include_empty'] ?? false)) {
            $q->whereRaw(self::LOT_TOTAL.' <> 0');
        }
        if (isset($f['expired'])) {
            filter_var($f['expired'], FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull('expires_on')->where('expires_on', '<', $today)
                : $q->where(fn ($w) => $w->whereNull('expires_on')->orWhere('expires_on', '>=', $today));
        }
        if (isset($f['expiring_within_days'])) {
            $q->whereNotNull('expires_on')->where('expires_on', '>=', $today)->where('expires_on', '<=', CarbonImmutable::parse($today)->addDays((int) $f['expiring_within_days'])->toDateString());
        }

        return $q->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('code')->orderBy('id')->paginate($f['per_page'] ?? 50);
    }

    public function movement(FarmContext $ctx, string $id): InventoryMovement
    {
        $ctx->authorize(Permission::InventoryView);

        return InventoryMovement::where('farm_id', $ctx->farm->id)->with(['item.stockUnit', 'lot', 'reversal'])->findOrFail($id);
    }

    public function formulas(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::InventoryView);
        $q = FeedFormula::where('farm_id', $ctx->farm->id)->with('items');
        if (! ($f['include_inactive'] ?? false)) {
            $q->where('is_active', true);
        }
        if (! empty($f['search'])) {
            $q->where('normalized_name', 'like', '%'.addcslashes(mb_strtolower(trim($f['search'])), '%_\\').'%');
        }

        return $q->orderBy('normalized_name')->orderBy('id')->paginate($f['per_page'] ?? 50);
    }
}
