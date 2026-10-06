<?php

namespace App\Services\Sales;

use App\Enums\CycleKind;
use App\Enums\InventoryCategory;
use App\Enums\Permission;
use App\Events\Sales\SaleRecorded;
use App\Http\Requests\Sales\CancelSaleRequest;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Models\Contact;
use App\Models\Farm;
use App\Models\FinanceCategory;
use App\Models\InventoryItem;
use App\Models\ProductionCycle;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Finance\FinanceService;
use App\Services\Inventory\InventoryService;
use App\Services\Records\RecordService;
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
 * The only writer of sales. A sale is the commercial/operational event: what left the farm, to whom, for how much. Recording one is
 * a single transaction that creates the sale and its lines, ONE Phase 9 stock-out (reason sale, linked to the line) per stock line (produce or feed)
 * and ONE population-ledger exit (an operational record of type livestock_sale with a negative population_delta, linked to the line)
 * per livestock line; other lines have no physical effect. Nothing here writes a balance, an invoice or a payment - an invoice is
 * issued separately (or alongside, as a convenience) and income is booked when a payment is received. Any failure rolls everything back.
 *
 * Cancelling appends compensating stock movements and population records and voids the unpaid invoice; it is refused while payments
 * are live (money is reversed explicitly, never implied) or when a livestock cycle has been closed.
 *
 * Lock order matches Phases 7-14: farm row, production cycle rows (id order), invoice/payment rows, inventory item rows.
 */
class SaleService
{
    private const RELATIONS = ['contact', 'category', 'items.item.stockUnit', 'items.lot', 'items.movement.reversal', 'items.record.reversal', 'invoice.payments.reversal'];

    public function __construct(private InventoryService $inventory, private RecordService $records, private FinanceService $finance, private InvoiceService $invoices) {}

    public function find(FarmContext $ctx, string $id): Sale
    {
        $ctx->authorize(Permission::SaleView);

        return Sale::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::SaleView);
        $q = Sale::where('farm_id', $ctx->farm->id)->with(self::RELATIONS);
        foreach (['status', 'contact_id'] as $column) {
            if (isset($f[$column])) {
                $q->where($column, $f[$column]);
            }
        }
        if (isset($f['payment_status'])) {
            $paid = '(SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.invoice_id = i.id AND p.entry_type = \'payment\' AND NOT EXISTS (SELECT 1 FROM payments r WHERE r.reverses_payment_id = p.id))';
            $live = 'FROM invoices i WHERE i.sale_id = sales.id AND i.status = \'issued\'';
            match ($f['payment_status']) {
                'uninvoiced' => $q->whereRaw('NOT EXISTS (SELECT 1 '.$live.')'),
                'unpaid' => $q->whereRaw('EXISTS (SELECT 1 '.$live.' AND '.$paid.' = 0)'),
                'paid' => $q->whereRaw('EXISTS (SELECT 1 '.$live.' AND '.$paid.' >= i.total_amount)'),
                'partially_paid' => $q->whereRaw('EXISTS (SELECT 1 '.$live.' AND '.$paid.' > 0 AND '.$paid.' < i.total_amount)'),
            };
        }
        if (! empty($f['search'])) {
            $term = '%'.addcslashes($f['search'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('reference', 'like', $term)->orWhere('customer_name', 'like', $term));
        }
        if (isset($f['from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($f['from'], $ctx->farm->timezone)->startOfDay()->utc());
        }
        if (isset($f['to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($f['to'], $ctx->farm->timezone)->addDay()->startOfDay()->utc());
        }

        return $q->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function create(FarmContext $ctx, array $input): Sale
    {
        $ctx->authorize(Permission::SaleCreate);
        $data = Validator::make($input, (new StoreSaleRequest)->rules())->validate();
        if (isset($data['corrects_sale_id'])) {
            $ctx->authorize(Permission::SaleCancel);
        }
        if (isset($data['invoice'])) {
            $ctx->authorize(Permission::InvoiceCreate);
        }

        return DB::transaction(function () use ($ctx, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of($data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $when = CarbonImmutable::parse($data['recorded_at'])->utc();
            if ($when->isFuture()) {
                $this->invalid('recorded_at', 'The event cannot be in the future.');
            }
            if (isset($data['contact_id']) && ! empty($data['customer_name'])) {
                $this->invalid('customer_name', 'Name the buyer with contact_id or customer_name, not both.');
            }
            $contactId = $this->finance->contact($ctx, $data['contact_id'] ?? null, customer: true);
            $customerName = $contactId !== null ? Contact::whereKey($contactId)->value('name') : (isset($data['customer_name']) ? trim(preg_replace('/\s+/u', ' ', $data['customer_name'])) : null);
            $this->assertCorrection($ctx, $data);
            $lines = $this->lines($ctx, $data['items']);
            $cycles = $this->lockCycles($ctx, $lines, $when);
            $total = Money::sum(array_map(fn ($line) => $line['amount'], $lines));
            $category = isset($data['finance_category_id']) ? $this->finance->category($ctx, $data['finance_category_id'], 'income') : $this->derivedCategory($lines);

            $number = Sale::where('farm_id', $ctx->farm->id)->count() + 1;
            $sale = Sale::create([
                'farm_id' => $ctx->farm->id, 'reference' => 'SAL-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'contact_id' => $contactId, 'customer_name' => $customerName ?: null, 'finance_category_id' => $category->id, 'recorded_at' => $when,
                'total_amount' => $total, 'currency' => $ctx->farm->currency, 'status' => Sale::ACTIVE, 'notes' => $data['notes'] ?? null,
                'corrects_sale_id' => $data['corrects_sale_id'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            $rows = [];
            foreach ($lines as $index => $line) {
                $rows[$index] = $this->addLine($ctx, $sale, $index, $line, $cycles, $when);
            }
            // Physical effects, item rows locked in id order so concurrent sales cannot deadlock.
            $order = array_keys($rows);
            usort($order, fn ($a, $b) => [$lines[$a]['kind'] === 'stock' ? $lines[$a]['inventory_item_id'] : '', $a] <=> [$lines[$b]['kind'] === 'stock' ? $lines[$b]['inventory_item_id'] : '', $b]);
            foreach ($order as $index) {
                $this->applyEffect($ctx, $sale, $rows[$index], $lines[$index], $cycles, $when, 'items.'.$index);
            }
            if (isset($data['invoice'])) {
                $this->invoices->issueFor($ctx, $sale, $data['invoice'], null, null);
            }
            SaleRecorded::dispatch($sale);

            return $sale->load(self::RELATIONS);
        }, 3);
    }

    /** Appends compensating stock movements / population records and voids the unpaid invoice; the sale keeps its history. */
    public function cancel(FarmContext $ctx, string $id, array $input): Sale
    {
        $ctx->authorize(Permission::SaleCancel);
        $data = Validator::make($input, (new CancelSaleRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['cancel' => $id] + $data);
            $existing = Sale::where('farm_id', $ctx->farm->id)->where('cancel_idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->cancel_request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $existing->load(self::RELATIONS);
            }
            $sale = Sale::where('farm_id', $ctx->farm->id)->lockForUpdate()->with(['items.record'])->findOrFail($id);
            if ($sale->isCancelled()) {
                throw new ApiHttpException(409, 'sale_already_cancelled', 'This sale has already been cancelled.');
            }
            $when = CarbonImmutable::parse($data['recorded_at'])->utc();
            if ($when->isFuture() || $when->lessThan($sale->recorded_at)) {
                $this->invalid('recorded_at', 'The cancellation must be between the sale and now.');
            }
            // Money first: a sale with payments still on it cannot be cancelled - reverse the payments explicitly.
            $invoice = $sale->invoice()->with('payments')->lockForUpdate()->first();
            if ($invoice !== null && ! Money::isZero($invoice->amountPaid())) {
                throw new ApiHttpException(409, 'sale_has_payments', 'Reverse the payments received on this sale before cancelling it.', details: ['amount_paid' => $invoice->amountPaid()]);
            }
            $cycleIds = $sale->items->where('kind', 'livestock')->pluck('production_cycle_id')->unique()->sort()->values();
            $cycles = [];
            foreach ($cycleIds as $cycleId) {
                $this->finance->resolveCycle($ctx, $cycleId);
                $cycles[$cycleId] = ProductionCycle::findOrFail($cycleId);
            }
            $lines = $sale->items->sortBy(fn ($line) => [$line->inventory_item_id ?? '', $line->line_no])->values();
            foreach ($lines as $line) {
                if ($line->kind === 'stock') {
                    $this->inventory->reverseForSale($ctx, $line->id, $sale->id, $when, $data['reason']);
                } elseif ($line->kind === 'livestock') {
                    $original = $line->record;
                    $this->records->appendForSale($ctx, $cycles[$line->production_cycle_id], ['reason' => $data['reason'], 'sale_id' => $sale->id, 'sale_item_id' => $line->id, 'head_count' => $line->head_count],
                        $line->head_count, $when, 'sale-cancel:'.$line->id, RequestHash::of(['sale_cancel' => $line->id]), $original);
                }
            }
            if ($invoice !== null) {
                $this->invoices->voidLocked($ctx, $invoice, 'Sale cancelled: '.$data['reason'], null, null);
            }
            $sale->update([
                'status' => Sale::CANCELLED, 'cancelled_at' => $when, 'cancel_reason' => $data['reason'], 'cancelled_by' => $ctx->membership->user_id,
                'cancel_idempotency_key' => $data['idempotency_key'], 'cancel_request_hash' => $hash,
            ]);
            SaleRecorded::dispatch($sale, 'cancelled');

            return $sale->load(self::RELATIONS);
        }, 3);
    }

    // -------------------------------------------------------------- helpers

    /**
     * Kind-specific line rules and money normalisation.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(FarmContext $ctx, array $items): array
    {
        $lines = [];
        foreach (array_values($items) as $index => $line) {
            $prefix = 'items.'.$index;
            $stockFields = ['inventory_item_id', 'output', 'storage_location_id', 'lot_id', 'components'];
            $kind = $line['kind'];
            if ($kind === 'stock') {
                $hasOutput = ! empty($line['output']);
                if ($hasOutput === ! empty($line['inventory_item_id'])) {
                    $this->invalid($prefix.($hasOutput ? '.output' : '.inventory_item_id'), 'Name the stock with exactly one of inventory_item_id or output.');
                }
                foreach ($hasOutput ? ['components'] : ['inventory_item_id', 'storage_location_id', 'components'] as $required) {
                    if (empty($line[$required])) {
                        $this->invalid($prefix.'.'.$required, 'This is required for a stock line.');
                    }
                }
                if ($hasOutput) {
                    $line = $this->inventory->resolveOutputLine($ctx, $line, $prefix, purchase: false);
                }
                if (! empty($line['description'])) {
                    $this->invalid($prefix.'.description', 'A stock line takes its name from the inventory item.');
                }
                if (array_key_exists('head_count', $line)) {
                    $this->invalid($prefix.'.head_count', 'Head count applies to livestock lines only.');
                }
            } else {
                foreach ($stockFields as $forbidden) {
                    if (array_key_exists($forbidden, $line) && $line[$forbidden] !== null) {
                        $this->invalid($prefix.'.'.$forbidden, 'Only stock lines take inventory fields.');
                    }
                }
                if ($kind === 'livestock') {
                    foreach (['production_cycle_id', 'head_count'] as $required) {
                        if (empty($line[$required])) {
                            $this->invalid($prefix.'.'.$required, 'This is required for a livestock line.');
                        }
                    }
                } else {
                    if (array_key_exists('head_count', $line)) {
                        $this->invalid($prefix.'.head_count', 'Head count applies to livestock lines only.');
                    }
                    if (trim((string) ($line['description'] ?? '')) === '') {
                        $this->invalid($prefix.'.description', 'Describe what was sold.');
                    }
                }
            }
            $line['amount'] = Money::parse($line['amount']);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Locks every cycle a line names (id order) and checks it is open; livestock lines need a livestock cycle and a date inside it.
     *
     * @return array<string, ProductionCycle>
     */
    private function lockCycles(FarmContext $ctx, array $lines, CarbonImmutable $when): array
    {
        $ids = collect($lines)->pluck('production_cycle_id')->filter()->unique()->sort()->values();
        $cycles = [];
        foreach ($ids as $id) {
            $this->finance->resolveCycle($ctx, $id);
            $cycles[$id] = ProductionCycle::with('livestock')->findOrFail($id);
        }
        foreach ($lines as $index => $line) {
            if ($line['kind'] !== 'livestock') {
                continue;
            }
            $cycle = $cycles[$line['production_cycle_id']];
            if ($cycle->kind !== CycleKind::Livestock) {
                $this->invalid('items.'.$index.'.production_cycle_id', 'Animals can only be sold from a livestock cycle.');
            }
            if ($when->lessThan(CarbonImmutable::parse($cycle->start_date->toDateString(), $ctx->farm->timezone)->startOfDay()->utc())) {
                $this->invalid('recorded_at', 'The sale cannot precede the start of the cycle the animals leave.');
            }
        }

        return $cycles;
    }

    /** The sale line row (immutable). Stock lines are measured and validated here; physical effects are applied afterwards. */
    private function addLine(FarmContext $ctx, Sale $sale, int $index, array $line, array $cycles, CarbonImmutable $when): SaleItem
    {
        $base = ['farm_id' => $ctx->farm->id, 'sale_id' => $sale->id, 'line_no' => $index + 1, 'kind' => $line['kind'], 'amount' => $line['amount'], 'production_cycle_id' => $line['production_cycle_id'] ?? null];
        $prefix = 'items.'.$index;
        if ($line['kind'] === 'other') {
            return SaleItem::create($base + ['description' => trim(preg_replace('/\s+/u', ' ', $line['description']))]);
        }
        if ($line['kind'] === 'livestock') {
            $label = trim(preg_replace('/\s+/u', ' ', (string) ($line['description'] ?? '')));

            return SaleItem::create($base + ['description' => $label !== '' ? $label : 'Livestock - '.$cycles[$line['production_cycle_id']]->name, 'head_count' => (int) $line['head_count']]);
        }
        $item = $this->inventory->itemForSale($ctx, $line['inventory_item_id'], $prefix.'.inventory_item_id');
        $result = $this->inventory->measureForItem($ctx, $item, $line['components'], $prefix.'.components');
        // Resolved before the row exists so foreign or unknown locations/lots fail as 404/409, not as a database constraint.
        [$location, $lot] = $this->inventory->saleSource($ctx, $item, $line, $when, $prefix);

        return SaleItem::create($base + [
            'description' => $item->name, 'inventory_item_id' => $item->id, 'storage_location_id' => $location->id, 'inventory_lot_id' => $lot?->id,
            'quantity' => Decimal::trim($result->normalized->value), 'measurement' => $result->toArray(),
        ]);
    }

    private function applyEffect(FarmContext $ctx, Sale $sale, SaleItem $row, array $line, array $cycles, CarbonImmutable $when, string $prefix): void
    {
        if ($line['kind'] === 'stock') {
            $item = $this->inventory->itemForSale($ctx, $row->inventory_item_id, $prefix.'.inventory_item_id');
            [$location, $lot] = $this->inventory->saleSource($ctx, $item, ['storage_location_id' => $row->storage_location_id, 'lot_id' => $row->inventory_lot_id], $when, $prefix);
            if ($lot !== null && $lot->id !== $row->inventory_lot_id) {
                $this->invalid($prefix.'.lot_id', 'Unknown lot.');
            }
            $this->inventory->issueForSale($ctx, $sale->id, $row->id, $item, $location, $lot, $row->measurement, $when, $prefix);
        } elseif ($line['kind'] === 'livestock') {
            $record = $this->records->appendForSale($ctx, $cycles[$row->production_cycle_id], ['sale_id' => $sale->id, 'sale_item_id' => $row->id, 'reference' => $sale->reference, 'head_count' => $row->head_count],
                -$row->head_count, $when, 'sale:'.$row->id, RequestHash::of(['sale_line' => $row->id]));
            $row->update(['operational_record_id' => $record->id]);
        }
    }

    /** One category when every line agrees (livestock -> livestock_sales, produce -> crop_sales), else other_income. Feed is not produce: surplus feed is other income. */
    private function derivedCategory(array $lines): FinanceCategory
    {
        $kinds = collect($lines)->pluck('kind')->unique();
        $onlyProduce = $kinds->first() !== 'stock' || ! InventoryItem::whereIn('id', collect($lines)->where('kind', 'stock')->pluck('inventory_item_id')->all())->where('category', '!=', InventoryCategory::Produce->value)->exists();
        $code = $kinds->count() === 1 ? match ($kinds->first()) {
            'livestock' => 'livestock_sales', 'stock' => $onlyProduce ? 'crop_sales' : 'other_income', default => 'other_income',
        } : 'other_income';

        return $this->finance->platformCategory($code, 'income');
    }

    private function assertCorrection(FarmContext $ctx, array $data): void
    {
        if (! isset($data['corrects_sale_id'])) {
            return;
        }
        $original = Sale::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($data['corrects_sale_id']);
        if (! $original->isCancelled() || Sale::where('corrects_sale_id', $original->id)->exists()) {
            throw new ApiHttpException(409, 'invalid_correction', 'Replace a cancelled sale once.');
        }
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?Sale
    {
        $sale = Sale::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($sale && ! hash_equals($sale->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $sale?->load(self::RELATIONS);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
