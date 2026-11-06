<?php

namespace App\Services\Inventory;

use App\Enums\PlaceKind;
use App\Events\Locations\PlaceChanged;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Locations\PlaceService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * The one place that answers "which store?" when a request names none.
 *
 *  - Stock coming IN uses the farm's main store; a farm without one gets "Main Store", created (and marked main) under the farm lock.
 *  - Stock going OUT uses the only store that holds the item (or lot); several stores = 422 "choose which store", none = no stock.
 *  - An explicit storage_location_id always wins and is validated exactly as before (farm-scoped, active for stock in).
 *
 * Every caller already holds the farm row lock (the order everywhere is farm, cycle, item); the resolver only reads balances and,
 * for the first stock-in, writes the main store under that same lock. It never touches the ledger.
 */
class StoreResolver
{
    public const DEFAULT_STORE = 'Main Store';

    public function __construct(private readonly PlaceService $places, private readonly StockLedger $ledger) {}

    /** The farm's active main store, or null when none is set. */
    public function main(FarmContext $ctx): ?StorageLocation
    {
        return StorageLocation::ofFarm($ctx->farm)->where('is_default', true)->where('is_active', true)->first();
    }

    /** Where a stock-IN lands. */
    public function receiving(FarmContext $ctx, ?string $locationId, string $field = 'storage_location_id'): StorageLocation
    {
        if ($locationId !== null) {
            return $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $locationId);
        }
        if (($main = $this->main($ctx)) !== null) {
            return $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $main->id);
        }

        return $this->createMain($ctx);
    }

    /**
     * Where a stock-OUT leaves from, or null when the item (or lot) has no stock in any store. Several stores holding it is a 422 on
     * $field. A named store is returned as-is (the caller's availability check then reports insufficient stock for that store).
     */
    public function issuing(FarmContext $ctx, InventoryItem $item, ?string $locationId, ?string $lotId = null, string $field = 'storage_location_id'): ?StorageLocation
    {
        if ($locationId !== null) {
            return $this->places->find($ctx->farm, PlaceKind::StorageLocation, $locationId);
        }
        $stores = [];
        foreach ($this->ledger->balances($item->id) as $row) {
            if (($lotId === null || $row['inventory_lot_id'] === $lotId) && Decimal::cmp($row['canonical'], '0') > 0) {
                $stores[$row['storage_location_id']] = true;
            }
        }
        if (count($stores) > 1) {
            throw ValidationException::withMessages([$field => 'Choose which store this comes from.']);
        }

        return $stores === [] ? null : $this->places->find($ctx->farm, PlaceKind::StorageLocation, (string) array_key_first($stores));
    }

    /** {@see issuing()}, with the ordinary 409 insufficient_stock when no store holds any. */
    public function issuingOrInsufficient(FarmContext $ctx, InventoryItem $item, ?string $locationId, ?string $lotId = null, string $field = 'storage_location_id'): StorageLocation
    {
        return $this->issuing($ctx, $item, $locationId, $lotId, $field)
            ?? throw new ApiHttpException(409, 'insufficient_stock', 'There is no '.$item->name.' in stock.', details: ['available' => $this->ledger->display($item, '0')]);
    }

    /** Root storage location "Main Store", created and marked main under the farm lock the caller already holds; a taken name gets a numeric suffix. */
    private function createMain(FarmContext $ctx): StorageLocation
    {
        Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
        if (($main = $this->main($ctx)) !== null) { // another request made one while this one waited for the farm lock
            return $main;
        }
        $name = self::DEFAULT_STORE;
        for ($n = 2; StorageLocation::ofFarm($ctx->farm)->whereNull('location_id')->where('normalized_name', mb_strtolower($name))->exists(); $n++) {
            $name = self::DEFAULT_STORE.' '.$n;
        }
        // A main flag may sit on an inactive store only if it was deactivated outside the application; clear it so the unique index admits the new one.
        StorageLocation::ofFarm($ctx->farm)->where('is_default', true)->update(['is_default' => false]);
        $store = new StorageLocation(['name' => $name, 'type' => 'store', 'is_active' => true]);
        $store->farm_id = $ctx->farm->id;
        $store->location_id = null;
        $store->is_default = true;
        $store->save();
        PlaceChanged::dispatch('created', $store, User::findOrFail($ctx->membership->user_id), ['name' => ['old' => null, 'new' => $name], 'type' => ['old' => null, 'new' => 'store'], 'parent_id' => ['old' => null, 'new' => null], 'is_active' => ['old' => null, 'new' => true], 'is_main' => ['old' => null, 'new' => true]]);

        return $store;
    }
}
