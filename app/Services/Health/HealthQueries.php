<?php

namespace App\Services\Health;

use App\Enums\InventoryCategory;
use App\Enums\Permission;
use App\Models\HealthRecord;
use App\Models\HealthRecordMedicine;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\MedicineProfile;
use App\Models\ProductionCycle;
use App\Support\Access\FarmContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Read side of health: farm-scoped, health.view gated, plus the medicine view over Phase 9 items. */
class HealthQueries
{
    private const ITEM_TOTAL = '(select COALESCE(SUM(m.quantity_delta), 0) from inventory_movements m where m.inventory_item_id = inventory_items.id)';

    public function records(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::HealthView);
        $q = HealthRecord::where('farm_id', $ctx->farm->id)->with(['reversal', 'medicines.item.stockUnit', 'medicines.lot', 'medicines.movement']);
        if (isset($f['production_cycle_id'])) {
            ProductionCycle::ofFarm($ctx->farm)->findOrFail($f['production_cycle_id']);
            $q->where('production_cycle_id', $f['production_cycle_id']);
        }
        if (isset($f['type'])) {
            $q->where('type', $f['type']);
        }
        if (isset($f['inventory_item_id'])) {
            InventoryItem::ofFarm($ctx->farm)->findOrFail($f['inventory_item_id']);
            $q->whereHas('medicines', fn ($m) => $m->where('inventory_item_id', $f['inventory_item_id']));
        }
        if (isset($f['recorded_from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($f['recorded_from'], $ctx->farm->timezone)->startOfDay()->utc());
        }
        if (isset($f['recorded_to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($f['recorded_to'], $ctx->farm->timezone)->addDay()->startOfDay()->utc());
        }

        return $q->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    /** Withdrawal windows, each traceable to the health record line that started it. Reversed records never count. */
    public function withdrawals(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::HealthView);
        $q = HealthRecordMedicine::where('farm_id', $ctx->farm->id)->whereNotNull('withdrawal_ends_at')
            ->whereDoesntHave('record.reversal')->with(['record', 'item.stockUnit', 'lot']);
        if (isset($f['production_cycle_id'])) {
            ProductionCycle::ofFarm($ctx->farm)->findOrFail($f['production_cycle_id']);
            $q->whereHas('record', fn ($r) => $r->where('production_cycle_id', $f['production_cycle_id']));
        }
        if (isset($f['inventory_item_id'])) {
            $q->where('inventory_item_id', $f['inventory_item_id']);
        }
        if (! isset($f['active']) || $f['active'] === '1' || $f['active'] === 1 || $f['active'] === true) {
            $q->where('withdrawal_ends_at', '>', now());
        }

        return $q->orderByDesc('withdrawal_ends_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function medicines(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::HealthView);
        $q = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->selectRaw('inventory_items.*, '.self::ITEM_TOTAL.' as stock_total')
            ->where('category', InventoryCategory::Medicine->value);
        if (! filter_var($f['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->where('is_active', true);
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
        $page = $q->orderBy('normalized_name')->orderBy('id')->paginate($f['per_page'] ?? 50);
        $this->attachProfiles($page->getCollection());

        return $page;
    }

    public function medicine(FarmContext $ctx, string $id): InventoryItem
    {
        $ctx->authorize(Permission::HealthView);
        $item = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->whereIn('category', $this->categories())->findOrFail($id);
        $this->attachProfiles(collect([$item]));

        return $item;
    }

    public function updateProfile(FarmContext $ctx, string $id, array $data): InventoryItem
    {
        $ctx->authorize(Permission::HealthManage);

        return DB::transaction(function () use ($ctx, $id, $data) {
            $item = InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->whereIn('category', $this->categories())->lockForUpdate()->findOrFail($id);
            $profile = MedicineProfile::firstOrNew(['inventory_item_id' => $item->id], ['farm_id' => $ctx->farm->id]);
            $profile->fill(['default_withdrawal_days' => $data['default_withdrawal_days']] + (array_key_exists('notes', $data) ? ['notes' => $data['notes']] : []));
            $profile->updated_by = $ctx->membership->user_id;
            $profile->save();
            $this->attachProfiles(collect([$item]));

            return $item;
        });
    }

    /** Lots with stock, expiring soonest first, for the medicine detail. */
    public function stockLots(InventoryItem $item): array
    {
        return InventoryLot::where('inventory_item_id', $item->id)
            ->selectRaw('inventory_lots.*, (select COALESCE(SUM(m.quantity_delta), 0) from inventory_movements m where m.inventory_lot_id = inventory_lots.id) as stock_total')
            ->whereRaw('(select COALESCE(SUM(m.quantity_delta), 0) from inventory_movements m where m.inventory_lot_id = inventory_lots.id) <> 0')
            ->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('id')->get()->all();
    }

    private function categories(): array
    {
        return [InventoryCategory::Medicine->value];
    }

    private function attachProfiles($items): void
    {
        $profiles = MedicineProfile::whereIn('inventory_item_id', $items->pluck('id'))->get()->keyBy('inventory_item_id');
        foreach ($items as $item) {
            $item->setRelation('medicineProfile', $profiles->get($item->id));
        }
    }
}
