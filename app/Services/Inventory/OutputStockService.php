<?php

namespace App\Services\Inventory;

use App\Enums\InventoryCategory;
use App\Enums\PlaceKind;
use App\Events\Locations\PlaceChanged;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\Locations\PlaceService;
use App\Services\Measurement\UnitCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The farm's egg and milk stock, without any inventory setup by the farmer.
 *
 * Eggs and milk are each ONE inventory item per farm, identified by `system_key` (output:eggs / output:milk): a produce item
 * counted in pieces (eggs) or litres (milk). It is resolved - or created - inside the caller's transaction while the farm row
 * is locked, so concurrent first collections cannot create two. The unique (farm_id, system_key) index is the backstop.
 *
 * A farmer's own compatible item named "Eggs"/"Milk" is ADOPTED (only system_key is assigned; nothing else is touched). An
 * incompatible one is left alone and a separate system item is created.
 *
 * Reads never create anything: {@see find()} returns null when the item does not exist yet.
 */
class OutputStockService
{
    public const DEFAULT_STORE = 'Main Store';

    /** kind => [system_key, name, stock unit family unit, dimension] */
    private const OUTPUTS = [
        StockReasonCatalogue::KIND_EGGS => ['name' => 'Eggs', 'unit' => 'piece', 'dimension' => 'count'],
        StockReasonCatalogue::KIND_MILK => ['name' => 'Milk', 'unit' => 'l', 'dimension' => 'volume'],
    ];

    public function __construct(private readonly UnitCatalogue $units, private readonly PlaceService $places, private readonly StockLedger $ledger) {}

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_keys(self::OUTPUTS);
    }

    public function find(FarmContext $ctx, string $kind, bool $forUpdate = false): ?InventoryItem
    {
        $item = InventoryItem::ofFarm($ctx->farm)->where('system_key', StockReasonCatalogue::OUTPUT_KEYS[$kind])->with('stockUnit.dimension')->when($forUpdate, fn ($q) => $q->lockForUpdate())->first();
        if ($item !== null && $forUpdate && ! $item->is_active) {
            throw new ApiHttpException(409, 'item_inactive', 'The '.$kind.' stock item is inactive.', details: ['inventory_item_id' => $item->id]);
        }

        return $item;
    }

    /**
     * The farm's output item, created or adopted on first use. Must run inside a transaction; locks the farm row first so the
     * lookup-then-create is serialized per farm (the lock order everywhere is farm, cycle, item).
     */
    public function resolve(FarmContext $ctx, string $kind, bool $forUpdate = true): InventoryItem
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Output items are resolved inside the caller\'s transaction.');
        }
        Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
        $item = InventoryItem::ofFarm($ctx->farm)->where('system_key', StockReasonCatalogue::OUTPUT_KEYS[$kind])->with('stockUnit.dimension')->when($forUpdate, fn ($q) => $q->lockForUpdate())->first();
        if ($item === null) {
            $item = $this->adopt($ctx, $kind) ?? $this->create($ctx, $kind);
        }
        if (! $item->is_active) {
            throw new ApiHttpException(409, 'item_inactive', 'The '.$kind.' stock item is inactive; reactivate it to record eggs or milk.', details: ['inventory_item_id' => $item->id]);
        }

        return $item;
    }

    /**
     * Where a stock-IN lands. Explicit location wins; a farm with exactly one active storage location uses it; a farm with
     * none gets "Main Store" (created once, under the farm lock); several active locations need an explicit choice.
     */
    public function receivingLocation(FarmContext $ctx, ?string $locationId, string $field): StorageLocation
    {
        if ($locationId !== null) {
            return $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $locationId);
        }
        $active = $this->activeLocations($ctx);
        if ($active->count() === 1) {
            return $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $active->first()->id);
        }
        if ($active->count() > 1) {
            $this->mustChoose($field);
        }

        return $this->createMainStore($ctx);
    }

    /**
     * Where a stock-OUT leaves from when the caller named no location: the only active storage location. Never creates a
     * store (there would be nothing to take); several active locations need an explicit choice.
     */
    public function issuingLocation(FarmContext $ctx, ?string $locationId, string $field): ?StorageLocation
    {
        if ($locationId !== null) {
            return $this->places->find($ctx->farm, PlaceKind::StorageLocation, $locationId);
        }
        $active = $this->activeLocations($ctx);
        if ($active->count() > 1) {
            $this->mustChoose($field);
        }

        return $active->count() === 1 ? $this->places->find($ctx->farm, PlaceKind::StorageLocation, $active->first()->id) : null;
    }

    /** Where returned eggs go: the named store, else where they were taken from. */
    public function returnLocation(FarmContext $ctx, ?string $locationId, ?string $originalLocationId): StorageLocation
    {
        return $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $locationId ?? $originalLocationId ?? throw ValidationException::withMessages(['egg_storage_location_id' => 'Choose a storage location.']));
    }

    // ------------------------------------------------------------ internals

    private function activeLocations(FarmContext $ctx)
    {
        return StorageLocation::ofFarm($ctx->farm)->where('is_active', true)->orderBy('id')->get();
    }

    private function mustChoose(string $field): never
    {
        throw ValidationException::withMessages([$field => 'This farm has more than one active storage location; choose which one.']);
    }

    /** Root storage location, created under the farm lock the caller already holds; a taken name gets a numeric suffix. */
    private function createMainStore(FarmContext $ctx): StorageLocation
    {
        Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
        $existing = StorageLocation::ofFarm($ctx->farm)->where('is_active', true)->orderBy('id')->first();
        if ($existing) { // another request created it while this one waited for the farm lock
            return $existing;
        }
        $name = self::DEFAULT_STORE;
        for ($n = 2; StorageLocation::ofFarm($ctx->farm)->whereNull('location_id')->where('normalized_name', mb_strtolower($name))->exists(); $n++) {
            $name = self::DEFAULT_STORE.' '.$n;
        }
        $store = new StorageLocation(['name' => $name, 'type' => 'store', 'is_active' => true]);
        $store->farm_id = $ctx->farm->id;
        $store->location_id = null;
        $store->save();
        PlaceChanged::dispatch('created', $store, User::findOrFail($ctx->membership->user_id), ['name' => ['old' => null, 'new' => $name], 'type' => ['old' => null, 'new' => 'store'], 'parent_id' => ['old' => null, 'new' => null], 'is_active' => ['old' => null, 'new' => true]]);

        return $store;
    }

    /** A farmer's own item named like the output, only when it is a plain produce item in the right unit family. */
    private function adopt(FarmContext $ctx, string $kind): ?InventoryItem
    {
        $spec = self::OUTPUTS[$kind];
        $candidate = InventoryItem::ofFarm($ctx->farm)->where('normalized_name', InventoryItem::normalizeName($spec['name']))->with('stockUnit.dimension')->lockForUpdate()->first();
        if ($candidate === null || $candidate->system_key !== null || ! $candidate->is_active || $candidate->category !== InventoryCategory::Produce
            || $candidate->tracks_lots || $candidate->tracks_expiry || $candidate->stockUnit->family !== $this->units->find($spec['unit'])->family) {
            return null;
        }
        $candidate->system_key = StockReasonCatalogue::OUTPUT_KEYS[$kind];
        $candidate->save();

        return $candidate;
    }

    private function create(FarmContext $ctx, string $kind): InventoryItem
    {
        $spec = self::OUTPUTS[$kind];
        $unit = $this->units->selectable($spec['unit']);
        $name = $spec['name'];
        for ($n = 1; InventoryItem::ofFarm($ctx->farm)->where('normalized_name', InventoryItem::normalizeName($name))->exists(); $n++) {
            $name = $spec['name'].' (farm output'.($n > 1 ? ' '.$n : '').')';
        }
        try {
            $item = new InventoryItem([
                'farm_id' => $ctx->farm->id, 'name' => $name, 'category' => InventoryCategory::Produce, 'stock_unit_id' => $unit->id, 'system_key' => StockReasonCatalogue::OUTPUT_KEYS[$kind],
                'tracks_lots' => false, 'tracks_expiry' => false, 'is_active' => true, 'created_by' => $ctx->membership->user_id,
            ]);
            $item->save();
        } catch (UniqueConstraintViolationException) {
            // Lost a race the farm lock should have prevented (e.g. a writer that did not take it): use the winner.
            return $this->find($ctx, $kind) ?? throw new ApiHttpException(409, 'inventory_item_exists', 'Could not create the '.$kind.' stock item; retry.');
        }

        return $item->load('stockUnit.dimension');
    }

    // ----------------------------------------------------------- read model

    /**
     * Available balance of each output, derived from the ledger. Read-only: an output that has never been stocked is reported
     * as exists=false with a zero balance, and nothing is created.
     *
     * @return array<string, array<string, mixed>>
     */
    public function balances(FarmContext $ctx): array
    {
        $out = [];
        foreach (self::OUTPUTS as $kind => $spec) {
            $item = $this->find($ctx, $kind);
            $unit = $item?->stockUnit ?? $this->units->find($spec['unit']);
            $canonical = $item ? $this->ledger->itemBalance($item->id) : '0';
            $display = $item
                ? $this->ledger->display($item, $canonical)
                : ['quantity' => '0', 'unit' => $unit->code];
            $out[$kind] = [
                'kind' => $kind, 'exists' => $item !== null, 'inventory_item_id' => $item?->id, 'name' => $item?->name ?? $spec['name'],
                'dimension' => $spec['dimension'], 'unit' => $unit->code,
                'available' => $display,
                'available_normalized' => $item ? $this->ledger->canonical($item, $canonical) : ['quantity' => '0', 'unit' => $kind === StockReasonCatalogue::KIND_EGGS ? 'piece' : 'ml'],
                'by_storage_location' => $item ? array_map(fn (array $row) => [
                    'storage_location_id' => $row['storage_location_id'], 'available' => $this->ledger->display($item, $row['canonical']),
                ], $this->ledger->balances($item->id)) : [],
            ];
        }

        return $out;
    }
}
