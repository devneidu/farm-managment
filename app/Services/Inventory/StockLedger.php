<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Unit;
use App\Services\Measurement\MeasurementConverter;
use App\Support\Api\ApiHttpException;
use App\Support\Measurement\Decimal;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitSpec;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of the ledger. Stock is never stored: every balance is a SUM over inventory_movements (canonical units:
 * g, ml or the count unit itself). Callers that guard against negative stock hold the farm lock before calling.
 */
class StockLedger
{
    /** @var array<string, UnitSpec> */
    private static array $canonical = [];

    public function __construct(private readonly MeasurementConverter $converter) {}

    private function bucket(Builder $q, string $itemId, string $locationId, ?string $lotId): Builder
    {
        $q->where('inventory_item_id', $itemId)->where('storage_location_id', $locationId);

        return $lotId === null ? $q->whereNull('inventory_lot_id') : $q->where('inventory_lot_id', $lotId);
    }

    /** Current canonical balance of one (item, storage location, lot) bucket. */
    public function bucketBalance(string $itemId, string $locationId, ?string $lotId): string
    {
        return $this->sum($this->bucket(InventoryMovement::query(), $itemId, $locationId, $lotId));
    }

    public function itemBalance(string $itemId): string
    {
        return $this->sum(InventoryMovement::where('inventory_item_id', $itemId));
    }

    private function sum(Builder $q): string
    {
        return Decimal::trim((string) ($q->toBase()->selectRaw('COALESCE(SUM(quantity_delta), 0) as total')->value('total') ?? '0'));
    }

    /**
     * Replays the bucket chronologically (recorded_at, then insertion order) and rejects any moment with negative stock.
     * Because it also covers back-dated issues and reversals, a "current balance" check alone is never trusted.
     */
    public function assertNeverNegative(string $itemId, string $locationId, ?string $lotId): void
    {
        $running = '0';
        $rows = $this->bucket(InventoryMovement::query(), $itemId, $locationId, $lotId)->orderBy('recorded_at')->orderBy('id')->lockForUpdate()->pluck('quantity_delta');
        foreach ($rows as $delta) {
            $running = Decimal::add($running, (string) $delta);
            if (Decimal::isNegative($running)) {
                throw new ApiHttpException(409, 'insufficient_stock', 'This movement would produce negative stock, including in the dated ledger history.');
            }
        }
    }

    /** Non-zero balances by storage location and lot for one item. */
    public function balances(string $itemId): array
    {
        return InventoryMovement::where('inventory_item_id', $itemId)->toBase()
            ->selectRaw('storage_location_id, inventory_lot_id, SUM(quantity_delta) as total')
            ->groupBy('storage_location_id', 'inventory_lot_id')->havingRaw('SUM(quantity_delta) <> 0')
            ->orderBy('storage_location_id')->orderBy('inventory_lot_id')->get()
            ->map(fn ($row) => ['storage_location_id' => $row->storage_location_id, 'inventory_lot_id' => $row->inventory_lot_id, 'canonical' => Decimal::trim((string) $row->total)])
            ->all();
    }

    /** @return array{quantity: string, unit: string} a canonical amount shown in the item's stock unit. */
    public function display(InventoryItem $item, string $canonical): array
    {
        $unit = $item->stockUnit;
        $quantity = new Quantity($canonical, $this->canonicalSpec($unit));

        return $this->converter->convert($quantity, $unit->spec())->toArray();
    }

    /** @return array{quantity: string, unit: string} */
    public function canonical(InventoryItem $item, string $canonical): array
    {
        return ['quantity' => $canonical, 'unit' => $this->canonicalSpec($item->stockUnit)->code];
    }

    private function canonicalSpec(Unit $unit): UnitSpec
    {
        return self::$canonical[$unit->family] ??= Unit::where('family', $unit->family)->where('is_canonical', true)->firstOrFail()->spec();
    }
}
