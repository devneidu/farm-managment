<?php

namespace App\Services\Health;

use App\Enums\ConversionContextType;
use App\Enums\InventoryCategory;
use App\Enums\MedicineType;
use App\Enums\Permission;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\MedicineProfile;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockReasonCatalogue;
use App\Services\Measurement\PackageConversionService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;

/**
 * One transactional "add a medicine" workflow built entirely from existing services: the Phase 9 item (InventoryService::createItem),
 * the withdrawal/type profile, an optional package conversion (PackageConversionService::create) and an optional opening balance
 * (InventoryService::stockIn, reason opening_balance). Nothing here writes stock, creates an expense or invents a supplier/purchase.
 */
class MedicineCatalogueService
{
    /** Primary (stock) units a medicine may be measured in. Counted medicines use `piece`; there are no tablet/dose units. */
    public const PRIMARY_UNITS = ['mg', 'g', 'kg', 'ml', 'l', 'piece'];

    /** Packaging units offered for medicines (all are package-dimension units, defined per medicine through a conversion). */
    public const PACKAGE_UNITS = ['bottle', 'box', 'card', 'sachet', 'bag', 'vial'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PackageConversionService $packages,
        private readonly HealthQueries $queries,
        private readonly StockReasonCatalogue $reasons,
    ) {}

    /** @return array<string, mixed> */
    public function options(): array
    {
        $units = fn (array $codes) => Unit::with('dimension')->whereIn('code', $codes)->where('is_active', true)->get()
            ->sortBy(fn (Unit $u) => array_search($u->code, $codes, true))->values();

        return [
            'medicine_types' => array_map(fn (MedicineType $t) => ['code' => $t->value, 'label' => $t->label()], MedicineType::cases()),
            'primary_units' => $units(self::PRIMARY_UNITS)->map(fn (Unit $u) => ['code' => $u->code, 'label' => $u->name, 'symbol' => $u->symbol, 'dimension' => $u->dimension->code, 'integer_only' => (bool) $u->integer_only])->all(),
            'package_units' => $units(self::PACKAGE_UNITS)->map(fn (Unit $u) => ['code' => $u->code, 'label' => $u->name])->all(),
            'withdrawal_days' => ['min' => 0, 'max' => 3650],
            'opening_stock' => ['reason' => 'opening_balance', 'route' => ['method' => 'POST', 'path' => '/health/medicines', 'field' => 'opening_stock']],
            'reasons' => $this->reasons->medicineReasons(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated StoreMedicineRequest input
     * @return array{0: InventoryItem, 1: bool} the medicine and whether this call created it (false = idempotent replay)
     */
    public function create(FarmContext $ctx, User $actor, array $data): array
    {
        $ctx->authorize(Permission::HealthManage);
        $ctx->authorize(Permission::InventoryManage);
        $packaging = $data['packaging'] ?? null;
        if ($packaging !== null) {
            $ctx->authorize(Permission::MeasurementManage);
        }
        $stock = $data['opening_stock'] ?? null;
        $lot = $stock['lot'] ?? null;
        $tracksExpiry = (bool) ($data['tracks_expiry'] ?? ($lot['expires_on'] ?? null) !== null);
        $tracksLots = (bool) ($data['tracks_lots'] ?? ($tracksExpiry || $lot !== null));

        $itemId = null;
        $created = DB::transaction(function () use ($ctx, $actor, $data, $packaging, $stock, $lot, $tracksLots, $tracksExpiry, &$itemId) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            if ($stock !== null && ($existing = $this->replayOf($ctx, $data, $stock)) !== null) {
                $itemId = $existing;

                return false;
            }

            $item = $this->inventory->createItem($ctx, [
                'name' => $data['name'], 'category' => InventoryCategory::Medicine->value, 'stock_unit' => $data['stock_unit'],
                'tracks_lots' => $tracksLots, 'tracks_expiry' => $tracksExpiry,
            ]);
            MedicineProfile::create([
                'farm_id' => $ctx->farm->id, 'inventory_item_id' => $item->id, 'medicine_type' => $data['medicine_type'],
                'default_withdrawal_days' => $data['default_withdrawal_days'] ?? null, 'notes' => $data['notes'] ?? null, 'updated_by' => $actor->id,
            ]);
            if ($packaging !== null) {
                $this->packages->create($ctx, $actor, [
                    'context_type' => ConversionContextType::InventoryItem->value, 'context_id' => $item->id,
                    'package_unit' => $packaging['unit'], 'target_unit' => $data['stock_unit'], 'quantity_per_package' => $packaging['quantity_per_package'],
                ]);
            }
            if ($stock !== null) {
                $this->inventory->stockIn($ctx, [
                    'inventory_item_id' => $item->id, 'storage_location_id' => $stock['storage_location_id'], 'reason' => 'opening_balance',
                    'components' => $stock['components'], 'recorded_at' => $stock['recorded_at'], 'idempotency_key' => $stock['idempotency_key'],
                ] + array_filter(['lot' => $lot, 'notes' => $stock['notes'] ?? null], fn ($v) => $v !== null));
            }
            $itemId = $item->id;

            return true;
        }, 3);

        return [$this->queries->medicine($ctx, $itemId), $created];
    }

    /** The medicine an earlier identical request already created under this opening-stock key; a different use of the key is a conflict. */
    private function replayOf(FarmContext $ctx, array $data, array $stock): ?string
    {
        $movement = InventoryMovement::where('farm_id', $ctx->farm->id)->where('idempotency_key', $stock['idempotency_key'])->first();
        if ($movement === null) {
            return null;
        }
        $item = InventoryItem::ofFarm($ctx->farm)->find($movement->inventory_item_id);
        if ($item === null || $item->category !== InventoryCategory::Medicine || $movement->reason !== 'opening_balance'
            || $item->normalized_name !== InventoryItem::normalizeName($data['name']) || $movement->storage_location_id !== $stock['storage_location_id']) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $item->id;
    }
}
