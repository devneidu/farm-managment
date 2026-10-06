<?php

namespace App\Services\Inventory;

use App\Enums\ConversionContextType;
use App\Enums\InventoryCategory;
use App\Enums\InventoryMovementType;
use App\Enums\Permission;
use App\Enums\PlaceKind;
use App\Events\Inventory\InventoryMovementRecorded;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\InventoryRules;
use App\Http\Requests\Inventory\ReverseMovementRequest;
use App\Http\Requests\Inventory\StockInRequest;
use App\Http\Requests\Inventory\StockOutRequest;
use App\Http\Requests\Inventory\StoreItemRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Http\Requests\Inventory\UpdateItemRequest;
use App\Models\Farm;
use App\Models\InventoryItem;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\OperationalRecord;
use App\Models\StorageLocation;
use App\Services\Locations\PlaceService;
use App\Services\Measurement\PackageConversionService;
use App\Services\Measurement\QuantityNormalizer;
use App\Services\Measurement\UnitCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Idempotency\RequestHash;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of items and of the stock ledger. Stock is never stored or overwritten: every change is an
 * append-only inventory_movements row, quantities are normalised by the Phase 5 QuantityNormalizer (packages resolve
 * only through the item's own conversion context), and negative stock is prevented under the farm lock by replaying the
 * affected (item, storage location, lot) bucket chronologically - not by trusting a stale balance.
 *
 * Lock order everywhere: farm row, (cycle row for record-driven effects), item row.
 */
class InventoryService
{
    private const DIMENSIONS = ['weight', 'volume', 'count'];

    public function __construct(
        private readonly QuantityNormalizer $quantities,
        private readonly PackageConversionService $packages,
        private readonly StockLedger $ledger,
        private readonly PlaceService $places,
        private readonly UnitCatalogue $units,
        private readonly OutputStockService $outputs,
    ) {}

    // ---------------------------------------------------------------- items

    public function createItem(FarmContext $ctx, array $input): InventoryItem
    {
        $ctx->authorize(Permission::InventoryManage);
        $data = $this->validated(StoreItemRequest::class, $input);
        $unit = $this->stockUnit($data['stock_unit']);
        $tracksLots = (bool) ($data['tracks_lots'] ?? false);
        $tracksExpiry = (bool) ($data['tracks_expiry'] ?? false);
        if ($tracksExpiry && ! $tracksLots) {
            $this->invalid('tracks_expiry', 'Expiry is tracked on lots; enable tracks_lots as well.');
        }

        return DB::transaction(function () use ($ctx, $data, $unit, $tracksLots, $tracksExpiry) {
            $this->lockFarm($ctx);
            $this->assertNameFree($ctx->farm, $data['name']);
            $item = new InventoryItem([
                'farm_id' => $ctx->farm->id, 'name' => $this->clean($data['name']), 'category' => $data['category'], 'stock_unit_id' => $unit->id,
                'tracks_lots' => $tracksLots, 'tracks_expiry' => $tracksExpiry, 'description' => $data['description'] ?? null,
                'is_active' => true, 'created_by' => $ctx->membership->user_id,
            ]);
            $item->setRelation('stockUnit', $unit);
            $item->low_stock_threshold = isset($data['low_stock_threshold']) ? $this->threshold($ctx->farm, $unit, $data['low_stock_threshold']) : null;
            $this->guardDuplicate(fn () => $item->save());

            return $item;
        }, 3);
    }

    public function updateItem(FarmContext $ctx, string $id, array $input): InventoryItem
    {
        $ctx->authorize(Permission::InventoryManage);
        $data = $this->validated(UpdateItemRequest::class, $input);

        return DB::transaction(function () use ($ctx, $id, $data) {
            $this->lockFarm($ctx);
            $item = $this->lockItem($ctx, $id);
            $hasMovements = $item->movements()->exists();
            $basis = ['category' => $item->category->value, 'tracks_lots' => $item->tracks_lots, 'tracks_expiry' => $item->tracks_expiry, 'stock_unit' => $item->stockUnit->code];
            $changed = array_filter(array_intersect_key($data, $basis), fn ($value, $key) => $value !== $basis[$key], ARRAY_FILTER_USE_BOTH);
            if ($changed !== [] && $hasMovements) {
                throw new ApiHttpException(409, 'item_has_movements', 'Category, stock unit and lot/expiry tracking cannot change once the item has stock history.', details: ['fields' => array_keys($changed)]);
            }
            if (isset($changed['stock_unit'])) {
                $unit = $this->stockUnit($changed['stock_unit']);
                $item->stock_unit_id = $unit->id;
                $item->setRelation('stockUnit', $unit);
                $item->low_stock_threshold = null;
            }
            foreach (['category', 'tracks_lots', 'tracks_expiry'] as $field) {
                if (isset($changed[$field])) {
                    $item->{$field} = $changed[$field];
                }
            }
            if ($item->tracks_expiry && ! $item->tracks_lots) {
                $this->invalid('tracks_expiry', 'Expiry is tracked on lots; enable tracks_lots as well.');
            }
            if (isset($data['name']) && InventoryItem::normalizeName($data['name']) !== $item->normalized_name) {
                $this->assertNameFree($ctx->farm, $data['name'], $item->id);
            }
            if (isset($data['name'])) {
                $item->name = $this->clean($data['name']);
            }
            if (array_key_exists('description', $data)) {
                $item->description = $data['description'];
            }
            if (array_key_exists('low_stock_threshold', $data)) {
                $item->low_stock_threshold = $data['low_stock_threshold'] === null ? null : $this->threshold($ctx->farm, $item->stockUnit, $data['low_stock_threshold']);
            }
            if (isset($data['is_active'])) {
                if (! $data['is_active'] && $item->is_active && ! Decimal::isZero($this->ledger->itemBalance($item->id))) {
                    throw new ApiHttpException(409, 'item_has_stock', 'Move, use or write off all stock before deactivating this item.');
                }
                $item->is_active = $data['is_active'];
            }
            $this->guardDuplicate(fn () => $item->save());

            return $item->refresh()->load('stockUnit.dimension');
        }, 3);
    }

    // ------------------------------------------------------------ movements

    /** @return list<InventoryMovement> */
    public function stockIn(FarmContext $ctx, array $input): array
    {
        $ctx->authorize(Permission::InventoryManage);
        $data = $this->validated(StockInRequest::class, $input);

        return $this->atomic($ctx, 'stock_in', $data, function (string $hash) use ($ctx, $data) {
            $item = isset($data['output']) ? $this->outputs->resolve($ctx, $data['output']) : $this->activeItem($ctx, $data['inventory_item_id']);
            $this->assertManualInReason($item, $data['reason']);
            $location = isset($data['output'])
                ? $this->outputs->receivingLocation($ctx, $data['storage_location_id'] ?? null, 'storage_location_id')
                : $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $data['storage_location_id']);
            $when = $this->when($data['recorded_at']);
            $lot = $this->lot($ctx, $item, $data, create: true);
            if ($lot?->expires_on && $lot->expires_on->toDateString() < $this->localDate($ctx, $when)) {
                throw new ApiHttpException(409, 'lot_expired', 'Stock cannot be received into a lot that had already expired on the received date.');
            }
            $result = $this->measure($ctx, $item, $data['components']);

            return [$this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockIn, $data['reason'], $this->positive($result->normalized->value), $result->toArray(), $when, [
                'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ])];
        });
    }

    /** @return list<InventoryMovement> */
    public function stockOut(FarmContext $ctx, array $input): array
    {
        $ctx->authorize(Permission::InventoryUse);
        $data = $this->validated(StockOutRequest::class, $input);

        return $this->atomic($ctx, 'stock_out', $data, function (string $hash) use ($ctx, $data) {
            if (isset($data['output'])) {
                // Never creates the item: a farm that never stocked eggs/milk has none to take out.
                $item = $this->outputs->find($ctx, $data['output'], forUpdate: true);
                $location = $item ? $this->outputs->issuingLocation($ctx, $data['storage_location_id'] ?? null, 'storage_location_id') : null;
                if ($item === null || $location === null) {
                    throw new ApiHttpException(409, 'insufficient_stock', 'There is no '.$data['output'].' in stock.', details: ['available' => ['quantity' => '0', 'unit' => $data['output'] === 'eggs' ? 'piece' : 'l']]);
                }
            } else {
                $item = $this->activeItem($ctx, $data['inventory_item_id']);
                $location = $this->places->find($ctx->farm, PlaceKind::StorageLocation, $data['storage_location_id']);
            }
            $this->assertManualOutReason($item, $data['reason']);
            $when = $this->when($data['recorded_at']);
            $lot = $this->lot($ctx, $item, $data, create: false);
            if ($data['reason'] === 'use') {
                $this->assertUsable($ctx, $lot, $when);
            }
            $result = $this->measure($ctx, $item, $data['components']);
            $qty = $this->positive($result->normalized->value);
            $this->assertAvailable($item, $location->id, $lot?->id, $qty);

            return [$this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockOut, $data['reason'], Decimal::sub('0', $qty), $result->toArray(), $when, [
                'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ], guard: true)];
        });
    }

    /** @return list<InventoryMovement> */
    public function adjust(FarmContext $ctx, array $input): array
    {
        $ctx->authorize(Permission::InventoryAdjust);
        $data = $this->validated(AdjustStockRequest::class, $input);

        return $this->atomic($ctx, 'adjustment', $data, function (string $hash) use ($ctx, $data) {
            $item = $this->activeItem($ctx, $data['inventory_item_id']);
            $location = $this->places->find($ctx->farm, PlaceKind::StorageLocation, $data['storage_location_id']);
            $when = $this->when($data['recorded_at']);
            $lot = $this->lot($ctx, $item, $data, create: false);
            $current = $this->ledger->bucketBalance($item->id, $location->id, $lot?->id);
            $expected = $this->measure($ctx, $item, $data['expected'], field: 'expected');
            if (Decimal::cmp($expected->normalized->value, $current) !== 0) {
                throw new ApiHttpException(409, 'stock_changed', 'The ledger balance changed; refresh the expected stock before reconciling.', details: ['current' => $this->ledger->display($item, $current)]);
            }
            $counted = $this->measure($ctx, $item, $data['counted'], field: 'counted');
            $delta = Decimal::sub($counted->normalized->value, $current);
            if (! Decimal::isNegative($delta) && ! Decimal::isZero($delta)) {
                $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $location->id);
            }
            if ($this->bucketQuery($item->id, $location->id, $lot?->id)->where('recorded_at', '>', $when)->exists()) {
                $this->invalid('recorded_at', 'A count cannot precede existing movements of this stock.');
            }
            $measurement = $counted->toArray() + ['adjustment' => ['expected' => $current, 'counted' => $counted->normalized->value, 'difference' => $delta]];

            return [$this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::Adjustment, null, $delta, $measurement, $when, [
                'justification' => $data['reason'], 'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ], guard: true)];
        });
    }

    /**
     * One business operation: a transfer_out and a transfer_in row sharing transfer_group_id, written in one transaction.
     *
     * @return list<InventoryMovement> [out, in]
     */
    public function transfer(FarmContext $ctx, array $input): array
    {
        $ctx->authorize(Permission::InventoryManage);
        $data = $this->validated(TransferStockRequest::class, $input);

        return $this->atomic($ctx, 'transfer', $data, function (string $hash) use ($ctx, $data) {
            $item = $this->activeItem($ctx, $data['inventory_item_id']);
            $from = $this->places->find($ctx->farm, PlaceKind::StorageLocation, $data['from_storage_location_id']);
            $to = $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $data['to_storage_location_id']);
            $when = $this->when($data['recorded_at']);
            $lot = $this->lot($ctx, $item, $data, create: false);
            $result = $this->measure($ctx, $item, $data['components']);
            $qty = $this->positive($result->normalized->value);
            $this->assertAvailable($item, $from->id, $lot?->id, $qty);
            $group = (string) Str::uuid7();
            $out = $this->insert($ctx, $item, $from->id, $lot, InventoryMovementType::TransferOut, null, Decimal::sub('0', $qty), $result->toArray(), $when, [
                'transfer_group_id' => $group, 'notes' => $data['notes'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ], guard: true);
            $in = $this->insert($ctx, $item, $to->id, $lot, InventoryMovementType::TransferIn, null, $qty, $result->toArray(), $when, ['transfer_group_id' => $group, 'notes' => $data['notes'] ?? null]);

            return [$out, $in];
        });
    }

    /**
     * Appends compensating rows (both legs of a transfer together). Movements caused by an operational record are
     * reversed by reversing the record instead.
     *
     * @return list<InventoryMovement>
     */
    public function reverse(FarmContext $ctx, string $id, array $input): array
    {
        $ctx->authorize(Permission::InventoryAdjust);
        $data = $this->validated(ReverseMovementRequest::class, $input);

        return $this->atomic($ctx, 'reversal:'.$id, $data, function (string $hash) use ($ctx, $id, $data) {
            $original = InventoryMovement::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
            if ($original->health_record_id !== null) {
                throw new ApiHttpException(409, 'reverse_via_health_record', 'This stock effect belongs to a health record; reverse the health record instead.');
            }
            if ($original->purchase_id !== null) {
                throw new ApiHttpException(409, 'reverse_via_purchase', 'This stock effect belongs to a purchase; cancel the purchase instead.');
            }
            if ($original->sale_id !== null) {
                throw new ApiHttpException(409, 'reverse_via_sale', 'This stock effect belongs to a sale; cancel the sale instead.');
            }
            if ($original->operational_record_id !== null) {
                throw new ApiHttpException(409, 'reverse_via_record', 'This stock effect belongs to an operational record; reverse the record instead.');
            }
            $legs = $original->transfer_group_id
                ? InventoryMovement::where('farm_id', $ctx->farm->id)->where('transfer_group_id', $original->transfer_group_id)->orderByRaw("type = 'transfer_in'")->orderBy('id')->lockForUpdate()->get()
                : collect([$original]);
            foreach ($legs as $leg) {
                if ($leg->type === InventoryMovementType::Reversal || $leg->reversal()->exists()) {
                    throw new ApiHttpException(409, 'movement_already_reversed', 'This movement is a reversal or has already been reversed.');
                }
            }
            $item = $this->lockItem($ctx, $original->inventory_item_id);
            $when = $this->when($data['recorded_at']);
            if ($when->lessThan($legs->max('recorded_at'))) {
                $this->invalid('recorded_at', 'A reversal cannot precede the movement it reverses.');
            }
            $group = $legs->count() > 1 ? (string) Str::uuid7() : null;
            $rows = [];
            foreach ($legs as $index => $leg) {
                $rows[] = $this->insert($ctx, $item, $leg->storage_location_id, $leg->lot, InventoryMovementType::Reversal, null, Decimal::sub('0', Decimal::trim((string) $leg->quantity_delta)), $leg->measurement, $when, [
                    'reverses_movement_id' => $leg->id, 'transfer_group_id' => $group, 'justification' => $data['reason'],
                    'idempotency_key' => $index === 0 ? $data['idempotency_key'] : null, 'request_hash' => $index === 0 ? $hash : null,
                ], guard: true);
            }

            return $rows;
        });
    }

    // ----------------------------------------------- Phase 8 integration

    /**
     * Validates the stock a feed_use record wants to consume and returns the locked item. Called by RecordService
     * inside its transaction (farm and cycle already locked).
     */
    public function feedItemForRecord(FarmContext $ctx, array $link): InventoryItem
    {
        $item = $this->activeItem($ctx, $link['item_id'], 'details.inventory.item_id');
        if ($item->category !== InventoryCategory::Feed) {
            $this->invalid('details.inventory.item_id', 'Feed use can only consume an inventory item in the feed category.');
        }
        if ($item->dimension() !== 'weight') {
            $this->invalid('details.inventory.item_id', 'Feed use records are measured by weight; choose a weight-based feed item.');
        }

        return $item;
    }

    /**
     * Phase 13: crop events only touch the stock category their record type names (fertilizer/agrochemical, seed/planting
     * material or produce), in a dimension that type allows. Called by RecordService inside its transaction
     * (farm and cycle already locked).
     *
     * @param  array{category: string, dimensions: list<string>}  $stock
     */
    public function cropStockItemForRecord(FarmContext $ctx, array $link, array $stock): InventoryItem
    {
        $item = $this->activeItem($ctx, $link['item_id'], 'details.inventory.item_id');
        if ($item->category->value !== $stock['category']) {
            $this->invalid('details.inventory.item_id', 'This crop event can only use an inventory item in the '.InventoryCategory::from($stock['category'])->label().' category.');
        }
        if (! in_array($item->dimension(), $stock['dimensions'], true)) {
            $this->invalid('details.inventory.item_id', 'This crop event is measured by '.implode(' or ', $stock['dimensions']).'; choose an item counted that way.');
        }

        return $item;
    }

    /**
     * Phase 13: one harvest record -> one stock_in (reason "harvest") of produce, in the record's transaction. A new lot may be
     * opened from details.inventory.lot; the receiving location must be an active storage location.
     */
    public function receiveForRecord(FarmContext $ctx, OperationalRecord $record, InventoryItem $item, array $link, array $measurement, CarbonImmutable $when, string $reason = 'harvest'): InventoryMovement
    {
        $location = $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $link['storage_location_id']);
        $lot = $this->lot($ctx, $item, $link, create: true, field: 'details.inventory.lot_id');
        if ($lot?->expires_on && $lot->expires_on->toDateString() < $this->localDate($ctx, $when)) {
            throw new ApiHttpException(409, 'lot_expired', 'Stock cannot be received into a lot that had already expired on the harvest date.');
        }
        $qty = $this->positive($measurement['normalized']['quantity'], 'details.components');

        return $this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockIn, $reason, $qty, $measurement, $when, ['operational_record_id' => $record->id, 'production_cycle_id' => $record->production_cycle_id]);
    }

    public function consumeForRecord(FarmContext $ctx, OperationalRecord $record, InventoryItem $item, array $link, array $measurement, CarbonImmutable $when, string $reason = 'use'): InventoryMovement
    {
        $location = $this->places->find($ctx->farm, PlaceKind::StorageLocation, $link['storage_location_id']);
        $lot = $this->lot($ctx, $item, ['lot_id' => $link['lot_id'] ?? null], create: false, field: 'details.inventory.lot_id');
        $this->assertUsable($ctx, $lot, $when);
        $qty = $this->positive($measurement['normalized']['quantity'], 'details.components');
        $this->assertAvailable($item, $location->id, $lot?->id, $qty);

        return $this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockOut, $reason, Decimal::sub('0', $qty), $measurement, $when, ['operational_record_id' => $record->id, 'production_cycle_id' => $record->production_cycle_id], guard: true);
    }

    /** Compensates the stock effect of a reversed record (if it had one). */
    public function reverseForRecord(FarmContext $ctx, OperationalRecord $original, OperationalRecord $reversal): ?InventoryMovement
    {
        $movement = InventoryMovement::where('farm_id', $ctx->farm->id)->where('operational_record_id', $original->id)->lockForUpdate()->first();
        if (! $movement) {
            return null;
        }
        $item = $this->lockItem($ctx, $movement->inventory_item_id);

        return $this->insert($ctx, $item, $movement->storage_location_id, $movement->lot, InventoryMovementType::Reversal, null, Decimal::sub('0', Decimal::trim((string) $movement->quantity_delta)), $movement->measurement, $reversal->recorded_at, [
            'operational_record_id' => $reversal->id, 'production_cycle_id' => $movement->production_cycle_id, 'reverses_movement_id' => $movement->id, 'justification' => $reversal->details['reason'] ?? null,
        ], guard: true);
    }

    // ----------------------------------------------- Breeding integration (incubation)

    /**
     * Eggs set for incubation leave available stock: one stock_out (reason "incubation") linked to the project, in the project's
     * transaction (farm and cycle already locked). Never lets the bucket go negative, now or in dated history.
     */
    public function consumeForBreeding(FarmContext $ctx, string $projectId, string $cycleId, InventoryItem $item, StorageLocation $location, int $eggs, CarbonImmutable $when): InventoryMovement
    {
        $measurement = $this->pieces($ctx, $eggs);
        $qty = $this->positive($measurement['normalized']['quantity'], 'eggs_set');
        $this->assertAvailable($item, $location->id, null, $qty);

        return $this->insert($ctx, $item, $location->id, null, InventoryMovementType::StockOut, 'incubation', Decimal::sub('0', $qty), $measurement, $when, ['breeding_project_id' => $projectId, 'production_cycle_id' => $cycleId], guard: true);
    }

    /** Eggs the farmer explicitly says go back to available stock (cancelled or reduced incubation): a stock_in (reason "returned") linked to the project. */
    public function returnForBreeding(FarmContext $ctx, string $projectId, string $cycleId, InventoryItem $item, StorageLocation $location, int $eggs, CarbonImmutable $when): InventoryMovement
    {
        $measurement = $this->pieces($ctx, $eggs);

        return $this->insert($ctx, $item, $location->id, null, InventoryMovementType::StockIn, 'returned', $this->positive($measurement['normalized']['quantity'], 'eggs_returned_to_stock'), $measurement, $when, ['breeding_project_id' => $projectId, 'production_cycle_id' => $cycleId]);
    }

    /**
     * What a project has taken from stock, derived from its movements: consumed, returned and the net still counted as in incubation,
     * plus where the first consumption came from (the default destination of a return).
     *
     * @return array{consumed: int, returned: int, net: int, storage_location_id: string|null, inventory_item_id: string|null}
     */
    public function incubationStock(FarmContext $ctx, string $projectId): array
    {
        $rows = InventoryMovement::where('farm_id', $ctx->farm->id)->where('breeding_project_id', $projectId)->orderBy('recorded_at')->orderBy('id')->get(['inventory_item_id', 'storage_location_id', 'reason', 'quantity_delta']);
        $consumed = '0';
        $returned = '0';
        foreach ($rows as $row) {
            $delta = Decimal::trim((string) $row->quantity_delta);
            if ($row->reason === 'incubation') {
                $consumed = Decimal::add($consumed, Decimal::sub('0', $delta));
            } else {
                $returned = Decimal::add($returned, $delta);
            }
        }

        return ['consumed' => (int) $consumed, 'returned' => (int) $returned, 'net' => (int) Decimal::sub($consumed, $returned), 'storage_location_id' => $rows->first()?->storage_location_id, 'inventory_item_id' => $rows->first()?->inventory_item_id];
    }

    private function pieces(FarmContext $ctx, int $eggs): array
    {
        return $this->quantities->normalize($ctx->farm, [['quantity' => (string) $eggs, 'unit' => 'piece']], null, 'piece', ['count'])->toArray();
    }

    // ----------------------------------------------- Phase 10 integration (health)

    /**
     * Validates and locks the medicine a health line wants to use: health records only use `medicine` items.
     * Called by HealthService inside its transaction.
     */
    public function medicineItemForHealth(FarmContext $ctx, string $itemId, string $prefix): InventoryItem
    {
        $item = $this->activeItem($ctx, $itemId, $prefix.'.inventory_item_id');
        if ($item->category !== InventoryCategory::Medicine) {
            $this->invalid($prefix.'.inventory_item_id', 'Health records can only use an inventory item in the medicine category.');
        }

        return $item;
    }

    /** Entered parts -> normalised quantity in the item's stock basis (packages resolve only through the item's own context). */
    public function measureForItem(FarmContext $ctx, InventoryItem $item, array $components, string $field)
    {
        return $this->measure($ctx, $item, $components, $field);
    }

    /** Entered parts with no fixed result unit, only the item's package context (a per-animal dose, for example). */
    public function measureLoose(FarmContext $ctx, InventoryItem $item, array $components, string $field): array
    {
        $context = $this->packages->resolveContext($ctx->farm, ConversionContextType::InventoryItem, $item->id, $field);

        return $this->quantities->normalize($ctx->farm, $components, $context, null, self::DIMENSIONS)->toArray();
    }

    /**
     * Resolves and validates where a medicine line draws stock from (before its row is written, so foreign or unknown
     * locations/lots fail as 404/409 rather than as a database constraint).
     *
     * @return array{0: StorageLocation, 1: InventoryLot|null}
     */
    public function healthSource(FarmContext $ctx, InventoryItem $item, array $link, CarbonImmutable $when, string $prefix): array
    {
        $location = $this->places->find($ctx->farm, PlaceKind::StorageLocation, $link['storage_location_id']);
        $lot = $this->lot($ctx, $item, ['lot_id' => $link['lot_id'] ?? null], create: false, field: $prefix.'.lot_id');
        $this->assertUsable($ctx, $lot, $when);

        return [$location, $lot];
    }

    /** One stock_out per medicine line, in the health record's transaction (farm and cycle already locked). */
    public function consumeForHealth(FarmContext $ctx, string $recordId, string $lineId, InventoryItem $item, StorageLocation $location, ?InventoryLot $lot, array $measurement, CarbonImmutable $when, string $prefix): InventoryMovement
    {
        $qty = $this->positive($measurement['normalized']['quantity'], $prefix.'.components');
        $this->assertAvailable($item, $location->id, $lot?->id, $qty);

        return $this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockOut, 'use', Decimal::sub('0', $qty), $measurement, $when, ['health_record_id' => $recordId, 'health_record_medicine_id' => $lineId], guard: true);
    }

    /** Compensates the stock a reversed health record consumed (one reversal movement per line). */
    public function reverseForHealth(FarmContext $ctx, string $lineId, string $reversalRecordId, CarbonImmutable $when, string $reason): ?InventoryMovement
    {
        $movement = InventoryMovement::where('farm_id', $ctx->farm->id)->where('health_record_medicine_id', $lineId)->lockForUpdate()->first();
        if (! $movement) {
            return null;
        }
        $item = $this->lockItem($ctx, $movement->inventory_item_id);

        return $this->insert($ctx, $item, $movement->storage_location_id, $movement->lot, InventoryMovementType::Reversal, null, Decimal::sub('0', Decimal::trim((string) $movement->quantity_delta)), $movement->measurement, $when, [
            'health_record_id' => $reversalRecordId, 'reverses_movement_id' => $movement->id, 'justification' => $reason,
        ], guard: true);
    }

    // ----------------------------------------------- Phase 14 integration (purchasing)

    /** Locks and returns the active item a stocked purchase line receives into. Called by PurchaseService inside its transaction. */
    public function itemForPurchase(FarmContext $ctx, string $itemId, string $field): InventoryItem
    {
        return $this->activeItem($ctx, $itemId, $field);
    }

    /**
     * Resolves where a stocked purchase line is received (before its row is written, so foreign ids fail as 404/409 rather
     * than as a database constraint): an active storage location and the lot (opened from `lot` when new).
     *
     * @return array{0: StorageLocation, 1: InventoryLot|null}
     */
    public function purchaseDestination(FarmContext $ctx, InventoryItem $item, array $link, CarbonImmutable $when, string $prefix): array
    {
        $location = $this->places->selectable($ctx->farm, PlaceKind::StorageLocation, $link['storage_location_id']);
        $lot = $this->lot($ctx, $item, $link, create: true, field: $prefix.'.lot_id');
        if ($lot?->expires_on && $lot->expires_on->toDateString() < $this->localDate($ctx, $when)) {
            throw new ApiHttpException(409, 'lot_expired', 'Stock cannot be received into a lot that had already expired on the purchase date.');
        }

        return [$location, $lot];
    }

    /** One stocked purchase line -> one stock_in (reason "purchase") in the purchase's transaction. */
    public function receiveForPurchase(FarmContext $ctx, string $purchaseId, string $lineId, InventoryItem $item, StorageLocation $location, ?InventoryLot $lot, array $measurement, CarbonImmutable $when, string $prefix): InventoryMovement
    {
        $qty = $this->positive($measurement['normalized']['quantity'], $prefix.'.components');

        return $this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockIn, 'purchase', $qty, $measurement, $when, ['purchase_id' => $purchaseId, 'purchase_item_id' => $lineId]);
    }

    /** Compensates the stock a cancelled purchase received (one reversal movement per stocked line). */
    public function reverseForPurchase(FarmContext $ctx, string $lineId, string $purchaseId, CarbonImmutable $when, string $reason): ?InventoryMovement
    {
        $movement = InventoryMovement::where('farm_id', $ctx->farm->id)->where('purchase_item_id', $lineId)->lockForUpdate()->first();
        if (! $movement) {
            return null;
        }
        $item = $this->lockItem($ctx, $movement->inventory_item_id);

        return $this->insert($ctx, $item, $movement->storage_location_id, $movement->lot, InventoryMovementType::Reversal, null, Decimal::sub('0', Decimal::trim((string) $movement->quantity_delta)), $movement->measurement, $when, [
            'purchase_id' => $purchaseId, 'reverses_movement_id' => $movement->id, 'justification' => $reason,
        ], guard: true);
    }

    // ----------------------------------------------- Phase 15 integration (sales)

    /** Locks and returns the active sellable (produce or feed) item a sale line sells. Called by SaleService inside its transaction. */
    public function itemForSale(FarmContext $ctx, string $itemId, string $field): InventoryItem
    {
        $item = $this->activeItem($ctx, $itemId, $field);
        if (! $item->category->isSellable()) {
            $this->invalid($field, 'Only '.implode(' or ', array_map(fn (InventoryCategory $c) => strtolower($c->label()), InventoryCategory::sellable())).' stock can be sold; record other stock leaving the farm with a stock-out.');
        }

        return $item;
    }

    /**
     * Resolves where a sold stock line leaves from (before any row is written, so foreign ids fail as 404/409 rather than as a
     * database constraint): a storage location and, for lot-tracked items, the named lot. Expired lots cannot be sold.
     *
     * @return array{0: StorageLocation, 1: InventoryLot|null}
     */
    public function saleSource(FarmContext $ctx, InventoryItem $item, array $link, CarbonImmutable $when, string $prefix): array
    {
        $location = $this->places->find($ctx->farm, PlaceKind::StorageLocation, $link['storage_location_id']);
        $lot = $this->lot($ctx, $item, ['lot_id' => $link['lot_id'] ?? null], create: false, field: $prefix.'.lot_id');
        if ($lot?->expires_on && $lot->expires_on->toDateString() < $this->localDate($ctx, $when)) {
            throw new ApiHttpException(409, 'lot_expired', 'Expired stock cannot be sold.');
        }

        return [$location, $lot];
    }

    /** One stocked sale line -> one stock_out (reason "sale") in the sale's transaction. Never lets the bucket go negative, now or in dated history. */
    public function issueForSale(FarmContext $ctx, string $saleId, string $lineId, InventoryItem $item, StorageLocation $location, ?InventoryLot $lot, array $measurement, CarbonImmutable $when, string $prefix): InventoryMovement
    {
        $qty = $this->positive($measurement['normalized']['quantity'], $prefix.'.components');
        $this->assertAvailable($item, $location->id, $lot?->id, $qty);

        return $this->insert($ctx, $item, $location->id, $lot, InventoryMovementType::StockOut, 'sale', Decimal::sub('0', $qty), $measurement, $when, ['sale_id' => $saleId, 'sale_item_id' => $lineId], guard: true);
    }

    /** Compensates the stock a cancelled sale issued (one reversal movement per stocked line). */
    public function reverseForSale(FarmContext $ctx, string $lineId, string $saleId, CarbonImmutable $when, string $reason): ?InventoryMovement
    {
        $movement = InventoryMovement::where('farm_id', $ctx->farm->id)->where('sale_item_id', $lineId)->lockForUpdate()->first();
        if (! $movement) {
            return null;
        }
        $item = $this->lockItem($ctx, $movement->inventory_item_id);

        return $this->insert($ctx, $item, $movement->storage_location_id, $movement->lot, InventoryMovementType::Reversal, null, Decimal::sub('0', Decimal::trim((string) $movement->quantity_delta)), $movement->measurement, $when, [
            'sale_id' => $saleId, 'reverses_movement_id' => $movement->id, 'justification' => $reason,
        ], guard: true);
    }

    // -------------------------------------------------------------- helpers

    private function atomic(FarmContext $ctx, string $operation, array $data, callable $work): array
    {
        return DB::transaction(function () use ($ctx, $operation, $data, $work) {
            $this->lockFarm($ctx);
            $hash = RequestHash::of(['operation' => $operation] + $data);
            $existing = InventoryMovement::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $this->legsOf($existing);
            }

            return $work($hash);
        }, 3);
    }

    /** @return list<InventoryMovement> */
    private function legsOf(InventoryMovement $anchor): array
    {
        if ($anchor->transfer_group_id === null) {
            return [$anchor];
        }

        return InventoryMovement::where('farm_id', $anchor->farm_id)->where('transfer_group_id', $anchor->transfer_group_id)->orderByRaw("type IN ('transfer_in')")->orderBy('id')->get()->all();
    }

    private function insert(FarmContext $ctx, InventoryItem $item, string $locationId, ?InventoryLot $lot, InventoryMovementType $type, ?string $reason, string $delta, array $measurement, CarbonImmutable $when, array $extra = [], bool $guard = false): InventoryMovement
    {
        $movement = InventoryMovement::create([
            'farm_id' => $ctx->farm->id, 'inventory_item_id' => $item->id, 'storage_location_id' => $locationId, 'inventory_lot_id' => $lot?->id,
            'type' => $type, 'reason' => $reason, 'quantity_delta' => $delta, 'measurement' => $measurement, 'recorded_at' => $when,
            'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
        ] + $extra);
        if ($guard) {
            $this->ledger->assertNeverNegative($item->id, $locationId, $lot?->id);
        }
        InventoryMovementRecorded::dispatch($movement);

        return $movement->load(['item.stockUnit', 'lot', 'reversal']);
    }

    private function assertAvailable(InventoryItem $item, string $locationId, ?string $lotId, string $qty): void
    {
        $available = $this->ledger->bucketBalance($item->id, $locationId, $lotId);
        if (Decimal::cmp($available, $qty) < 0) {
            throw new ApiHttpException(409, 'insufficient_stock', 'There is not enough stock in this location'.($lotId ? ' and lot' : '').'.', details: [
                'available' => $this->ledger->display($item, $available), 'requested' => $this->ledger->display($item, $qty),
            ]);
        }
    }

    /**
     * "Produced on farm" is a manual stock-in only for stock no operational record explains (home-mixed feed...). Produce comes
     * from its record - egg_collection, milk, crop_harvest - so a manual production IN would create stock with no production.
     */
    /** Feed eaten by livestock is entered once, as a feed_use record; the generic "use" reason would decrement it a second time. */
    private function assertManualOutReason(InventoryItem $item, string $reason): void
    {
        if ($reason === 'use' && $item->category === InventoryCategory::Feed) {
            $this->invalid('reason', 'Feed used for livestock is recorded through POST /records (type feed_use) so the event is entered once; the generic "use" reason is not accepted for feed. Use donation, spoiled, lost, disposal, internal_use or other for feed leaving the farm in another way.');
        }
    }

    private function assertManualInReason(InventoryItem $item, string $reason): void
    {
        if ($reason === 'production' && ($item->category === InventoryCategory::Produce || $item->system_key !== null)) {
            $this->invalid('reason', 'Produce is stocked by recording the production (POST /records: egg_collection, milk or crop_harvest), so stock and production cannot disagree. Use donation, purchase, received, opening_balance or other for produce that was not produced on this farm.');
        }
    }

    private function assertUsable(FarmContext $ctx, ?InventoryLot $lot, CarbonImmutable $when): void
    {
        if ($lot?->expires_on && $lot->expires_on->toDateString() < $this->localDate($ctx, $when)) {
            throw new ApiHttpException(409, 'lot_expired', 'Expired stock cannot be used; write it off with reason "expired" or "wasted".');
        }
    }

    /** Lot selection rules: lot-tracked items always name a lot; other items never do. There is no automatic FEFO. */
    private function lot(FarmContext $ctx, InventoryItem $item, array $data, bool $create, string $field = 'lot_id'): ?InventoryLot
    {
        $id = $data['lot_id'] ?? null;
        $new = $data['lot'] ?? null;
        if (! $item->tracks_lots) {
            if ($id !== null || $new !== null) {
                $this->invalid($field, 'This item does not track lots.');
            }

            return null;
        }
        if ($id === null && $new === null) {
            $this->invalid($field, 'This item tracks lots; choose a lot.');
        }
        if ($id !== null) {
            return InventoryLot::where('farm_id', $ctx->farm->id)->where('inventory_item_id', $item->id)->findOrFail($id);
        }
        $expires = $new['expires_on'] ?? null;
        if ($expires !== null && ! $item->tracks_expiry) {
            $this->invalid('lot.expires_on', 'This item does not track expiry.');
        }
        $existing = InventoryLot::where('inventory_item_id', $item->id)->where('normalized_code', InventoryLot::normalizeCode($new['code']))->first();
        if ($existing) {
            if ($expires !== null && $existing->expires_on?->toDateString() !== $expires) {
                $this->invalid('lot.expires_on', 'This lot code already exists with a different expiry date; lots are immutable.');
            }

            return $existing;
        }
        if ($item->tracks_expiry && $expires === null) {
            $this->invalid('lot.expires_on', 'An expiry date is required for this item.');
        }

        return InventoryLot::create([
            'farm_id' => $ctx->farm->id, 'inventory_item_id' => $item->id, 'code' => $this->clean($new['code']),
            'normalized_code' => InventoryLot::normalizeCode($new['code']), 'expires_on' => $expires, 'created_by' => $ctx->membership->user_id,
        ]);
    }

    /** Entered parts -> normalised quantity in the item's measurement basis; packages resolve only via the item's own context. */
    private function measure(FarmContext $ctx, InventoryItem $item, array $components, string $field = 'components')
    {
        $context = $this->packages->resolveContext($ctx->farm, ConversionContextType::InventoryItem, $item->id, $field);

        return $this->quantities->normalize($ctx->farm, $components, $context, $item->stockUnit->code, [$item->dimension()]);
    }

    private function threshold(Farm $farm, $unit, array $threshold): string
    {
        return $this->quantities->normalize($farm, [$threshold], null, $unit->code, [$unit->dimension->code])->normalized->value;
    }

    private function positive(string $value, string $field = 'components'): string
    {
        if (Decimal::isZero($value) || Decimal::isNegative($value)) {
            $this->invalid($field, 'The quantity must be greater than zero.');
        }

        return $value;
    }

    private function stockUnit(string $code)
    {
        $unit = $this->units->selectable($code);
        if (! in_array($unit->dimension->code, self::DIMENSIONS, true) || $unit->family === null) {
            $this->invalid('stock_unit', 'Stock is counted in a weight, volume or count unit, never a package.');
        }

        return $unit;
    }

    private function activeItem(FarmContext $ctx, string $id, string $field = 'inventory_item_id'): InventoryItem
    {
        $item = $this->lockItem($ctx, $id);
        if (! $item->is_active) {
            throw new ApiHttpException(409, 'item_inactive', 'This inventory item is inactive.', details: ['field' => $field]);
        }

        return $item;
    }

    private function lockItem(FarmContext $ctx, string $id): InventoryItem
    {
        return InventoryItem::ofFarm($ctx->farm)->with('stockUnit.dimension')->lockForUpdate()->findOrFail($id);
    }

    private function bucketQuery(string $itemId, string $locationId, ?string $lotId)
    {
        $q = InventoryMovement::where('inventory_item_id', $itemId)->where('storage_location_id', $locationId);

        return $lotId === null ? $q->whereNull('inventory_lot_id') : $q->where('inventory_lot_id', $lotId);
    }

    private function lockFarm(FarmContext $ctx): void
    {
        Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
    }

    private function assertNameFree(Farm $farm, string $name, ?string $ignore = null): void
    {
        $q = InventoryItem::ofFarm($farm)->where('normalized_name', InventoryItem::normalizeName($name));
        if ($ignore) {
            $q->where('id', '!=', $ignore);
        }
        if ($q->exists()) {
            throw new ApiHttpException(409, 'inventory_item_exists', 'An inventory item with this name already exists on this farm.');
        }
    }

    private function guardDuplicate(callable $work): void
    {
        try {
            $work();
        } catch (UniqueConstraintViolationException) {
            throw new ApiHttpException(409, 'inventory_item_exists', 'An inventory item with this name already exists on this farm.');
        }
    }

    private function when(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value)->utc();
        if ($date->isFuture()) {
            $this->invalid('recorded_at', 'The event cannot be in the future.');
        }

        return $date;
    }

    private function localDate(FarmContext $ctx, CarbonImmutable $when): string
    {
        return $when->setTimezone($ctx->farm->timezone)->toDateString();
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function validated(string $request, array $input): array
    {
        return Validator::make($input, (new $request)->rules() + InventoryRules::forbidden())->validate();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
