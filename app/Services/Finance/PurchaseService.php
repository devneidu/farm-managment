<?php

namespace App\Services\Finance;

use App\Enums\InventoryCategory;
use App\Enums\Permission;
use App\Events\Purchasing\PurchaseRecorded;
use App\Http\Requests\Purchases\CancelPurchaseRequest;
use App\Http\Requests\Purchases\StorePurchaseRequest;
use App\Models\Farm;
use App\Models\FinanceCategory;
use App\Models\InventoryItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\Inventory\InventoryService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Finance\Money;
use App\Support\Idempotency\RequestHash;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of purchases. A purchase is a procurement document; recording one is a single transaction that creates
 * its lines, one Phase 9 stock-in per stocked line (never a direct balance change), and - unless opted out - exactly one
 * expense in the money ledger linked back to it. Non-stock lines (services, transport) create no stock movement.
 * Cancelling appends compensating stock movements and an offsetting ledger row; nothing is edited or deleted.
 *
 * Lock order matches Phases 7-10: farm row, cycle row, inventory item rows.
 */
class PurchaseService
{
    private const RELATIONS = ['contact', 'category', 'items.item.stockUnit', 'items.lot', 'items.movement', 'transaction.reversal'];

    /** Inventory category -> default expense category code. */
    private const CATEGORY_CODES = [
        InventoryCategory::Feed->value => 'feed',
        InventoryCategory::Medicine->value => 'medicine_veterinary',
        InventoryCategory::SeedPlantingMaterial->value => 'seed_planting_material',
        InventoryCategory::FertilizerAgrochemical->value => 'fertilizer_agrochemical',
        InventoryCategory::GeneralSupply->value => 'general_supplies',
        InventoryCategory::Produce->value => 'general_supplies',
    ];

    public function __construct(private InventoryService $inventory, private FinanceService $finance) {}

    public function find(FarmContext $ctx, string $id): Purchase
    {
        $ctx->authorize(Permission::PurchaseView);

        return Purchase::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::PurchaseView);
        $q = Purchase::where('farm_id', $ctx->farm->id)->with(self::RELATIONS);
        foreach (['status', 'contact_id', 'production_cycle_id'] as $column) {
            if (isset($f[$column])) {
                $q->where($column, $f[$column]);
            }
        }
        if (! empty($f['search'])) {
            $term = '%'.addcslashes($f['search'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('reference', 'like', $term)->orWhere('supplier_reference', 'like', $term));
        }
        if (isset($f['from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($f['from'], $ctx->farm->timezone)->startOfDay()->utc());
        }
        if (isset($f['to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($f['to'], $ctx->farm->timezone)->addDay()->startOfDay()->utc());
        }

        return $q->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function create(FarmContext $ctx, array $input): Purchase
    {
        $ctx->authorize(Permission::PurchaseCreate);
        $data = Validator::make($input, (new StorePurchaseRequest)->rules())->validate();
        if (isset($data['corrects_purchase_id'])) {
            $ctx->authorize(Permission::PurchaseCancel);
        }

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of($data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $cycleId = $this->finance->resolveCycle($ctx, $data['production_cycle_id'] ?? null);
            $contactId = $this->finance->contact($ctx, $data['contact_id'] ?? null, supplier: true);
            $when = CarbonImmutable::parse($data['recorded_at'])->utc();
            if ($when->isFuture()) {
                $this->invalid('recorded_at', 'The event cannot be in the future.');
            }
            $this->assertCorrection($ctx, $data);
            $lines = $this->lines($ctx, $data['items']);
            $total = Money::sum(array_map(fn ($line) => $line['amount'], $lines));
            $recordExpense = $data['record_expense'] ?? true;
            $category = isset($data['finance_category_id'])
                ? $this->finance->category($ctx, $data['finance_category_id'], 'expense')
                : $this->derivedCategory($lines);

            $number = Purchase::where('farm_id', $ctx->farm->id)->count() + 1;
            $purchase = Purchase::create([
                'farm_id' => $ctx->farm->id, 'reference' => 'PUR-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'contact_id' => $contactId, 'production_cycle_id' => $cycleId, 'finance_category_id' => $category->id,
                'supplier_reference' => $data['supplier_reference'] ?? null, 'recorded_at' => $when, 'total_amount' => $total, 'currency' => $ctx->farm->currency,
                'status' => Purchase::ACTIVE, 'records_expense' => $recordExpense, 'notes' => $data['notes'] ?? null,
                'corrects_purchase_id' => $data['corrects_purchase_id'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            foreach ($lines as $index => $line) {
                $this->addLine($ctx, $purchase, $index, $line, $when);
            }
            if ($recordExpense) {
                $this->finance->bookPurchase($ctx, $purchase, $category);
            }
            PurchaseRecorded::dispatch($purchase);

            return $purchase->load(self::RELATIONS);
        }, 3);
    }

    /** Appends compensating stock movements and an offsetting ledger row; the purchase keeps its history. */
    public function cancel(FarmContext $ctx, string $id, array $input): Purchase
    {
        $ctx->authorize(Permission::PurchaseCancel);
        $data = Validator::make($input, (new CancelPurchaseRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['cancel' => $id] + $data);
            $existing = Purchase::where('farm_id', $ctx->farm->id)->where('cancel_idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->cancel_request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $existing->load(self::RELATIONS);
            }
            $purchase = Purchase::where('farm_id', $ctx->farm->id)->lockForUpdate()->with('items')->findOrFail($id);
            if ($purchase->isCancelled()) {
                throw new ApiHttpException(409, 'purchase_already_cancelled', 'This purchase has already been cancelled.');
            }
            $when = CarbonImmutable::parse($data['recorded_at'])->utc();
            if ($when->isFuture() || $when->lessThan($purchase->recorded_at)) {
                $this->invalid('recorded_at', 'The cancellation must be between the purchase and now.');
            }
            if ($purchase->production_cycle_id !== null) {
                $this->finance->resolveCycle($ctx, $purchase->production_cycle_id);
            }
            foreach ($purchase->items as $line) {
                if ($line->kind === 'stock') {
                    $this->inventory->reverseForPurchase($ctx, $line->id, $purchase->id, $when, $data['reason']);
                }
            }
            $this->finance->reversePurchase($ctx, $purchase, $when, $data['reason']);
            $purchase->update([
                'status' => Purchase::CANCELLED, 'cancelled_at' => $when, 'cancel_reason' => $data['reason'], 'cancelled_by' => $ctx->membership->user_id,
                'cancel_idempotency_key' => $data['idempotency_key'], 'cancel_request_hash' => $hash,
            ]);
            PurchaseRecorded::dispatch($purchase, 'cancelled');

            return $purchase->load(self::RELATIONS);
        }, 3);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Kind-specific line rules (stock lines name inventory fields, non-stock lines forbid them) and money normalisation.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(FarmContext $ctx, array $items): array
    {
        $lines = [];
        foreach (array_values($items) as $index => $line) {
            $prefix = 'items.'.$index;
            $stockFields = ['inventory_item_id', 'storage_location_id', 'lot_id', 'lot', 'components'];
            if ($line['kind'] === 'stock') {
                foreach (['inventory_item_id', 'storage_location_id', 'components'] as $required) {
                    if (empty($line[$required])) {
                        $this->invalid($prefix.'.'.$required, 'This is required for a stock line.');
                    }
                }
                if (! empty($line['description'])) {
                    $this->invalid($prefix.'.description', 'A stock line takes its name from the inventory item.');
                }
            } else {
                foreach ($stockFields as $forbidden) {
                    if (array_key_exists($forbidden, $line) && $line[$forbidden] !== null) {
                        $this->invalid($prefix.'.'.$forbidden, 'A non-stock line creates no inventory movement; remove inventory fields.');
                    }
                }
                if (trim((string) ($line['description'] ?? '')) === '') {
                    $this->invalid($prefix.'.description', 'Describe what was bought.');
                }
            }
            $line['amount'] = Money::parse($line['amount']);
            $lines[] = $line;
        }

        return $lines;
    }

    private function addLine(FarmContext $ctx, Purchase $purchase, int $index, array $line, CarbonImmutable $when): void
    {
        $prefix = 'items.'.$index;
        if ($line['kind'] === 'non_stock') {
            PurchaseItem::create([
                'farm_id' => $ctx->farm->id, 'purchase_id' => $purchase->id, 'line_no' => $index + 1, 'kind' => 'non_stock',
                'description' => trim(preg_replace('/\s+/u', ' ', $line['description'])), 'amount' => $line['amount'],
            ]);

            return;
        }
        $item = $this->inventory->itemForPurchase($ctx, $line['inventory_item_id'], $prefix.'.inventory_item_id');
        $result = $this->inventory->measureForItem($ctx, $item, $line['components'], $prefix.'.components');
        [$location, $lot] = $this->inventory->purchaseDestination($ctx, $item, $line, $when, $prefix);
        $row = PurchaseItem::create([
            'farm_id' => $ctx->farm->id, 'purchase_id' => $purchase->id, 'line_no' => $index + 1, 'kind' => 'stock', 'description' => $item->name,
            'inventory_item_id' => $item->id, 'storage_location_id' => $location->id, 'inventory_lot_id' => $lot?->id,
            'quantity' => Decimal::trim($result->normalized->value), 'measurement' => $result->toArray(), 'amount' => $line['amount'],
        ]);
        $this->inventory->receiveForPurchase($ctx, $purchase->id, $row->id, $item, $location, $lot, $result->toArray(), $when, $prefix);
    }

    /** One category when every line agrees (stock lines by inventory category, non-stock lines other_expense), else general_supplies. */
    private function derivedCategory(array $lines): FinanceCategory
    {
        $codes = [];
        foreach ($lines as $line) {
            $codes[$this->lineCode($line)] = true;
        }
        $code = count($codes) === 1 ? array_key_first($codes) : 'general_supplies';

        return $this->finance->platformCategory($code);
    }

    private function lineCode(array $line): string
    {
        if ($line['kind'] === 'non_stock') {
            return 'other_expense';
        }
        $category = InventoryItem::whereKey($line['inventory_item_id'])->value('category');

        return self::CATEGORY_CODES[$category->value ?? $category] ?? 'general_supplies';
    }

    private function assertCorrection(FarmContext $ctx, array $data): void
    {
        if (! isset($data['corrects_purchase_id'])) {
            return;
        }
        $original = Purchase::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($data['corrects_purchase_id']);
        if (! $original->isCancelled() || Purchase::where('corrects_purchase_id', $original->id)->exists()) {
            throw new ApiHttpException(409, 'invalid_correction', 'Replace a cancelled purchase once.');
        }
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?Purchase
    {
        $purchase = Purchase::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($purchase && ! hash_equals($purchase->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $purchase?->load(self::RELATIONS);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
