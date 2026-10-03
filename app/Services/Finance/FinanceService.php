<?php

namespace App\Services\Finance;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Events\Finance\FinanceTransactionRecorded;
use App\Http\Requests\Finance\ReverseTransactionRequest;
use App\Http\Requests\Finance\StoreTransactionRequest;
use App\Models\Contact;
use App\Models\Farm;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\HealthRecord;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Models\Purchase;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Finance\Money;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of the money ledger. One real-world financial event is ONE entry: a source link (purchase, operational
 * record or health record) is unique while its entry is live, so "record as expense" can never double-book, and a purchase
 * books its own expense instead of asking the user to enter it again. Rows are append-only; a reversal row offsets the
 * original in every sum. Money never touches physical stock: this service writes no inventory, population or record rows.
 *
 * Lock order matches Phases 7-10: farm row, cycle row (and inventory item rows, which only the purchase service takes).
 */
class FinanceService
{
    public const SOURCES = ['operational_record', 'health_record', 'purchase'];

    /** Sources the ledger can be filtered by: the client-bookable ones plus the entries other modules book themselves. */
    public const FILTER_SOURCES = [...self::SOURCES, 'payment'];

    private const RELATIONS = ['category', 'contact', 'reversal'];

    public function categories(FarmContext $ctx, array $filters = [])
    {
        $ctx->authorize(Permission::FinanceView);
        $q = FinanceCategory::visibleTo($ctx->farm)->where('is_active', true);
        if (isset($filters['direction'])) {
            $q->where('direction', $filters['direction']);
        }

        return $q->orderBy('direction')->orderBy('sort_order')->orderBy('name')->get();
    }

    public function find(FarmContext $ctx, string $id): FinanceTransaction
    {
        $ctx->authorize(Permission::FinanceView);

        return FinanceTransaction::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    /**
     * POST /finance/transactions, /expenses and /income. $forced pins the direction for the shortcut endpoints.
     */
    public function record(FarmContext $ctx, array $input, ?string $forced = null): FinanceTransaction
    {
        $ctx->authorize(Permission::FinanceCreate);
        if ($forced !== null) {
            $input['direction'] = $input['direction'] ?? $forced;
        }
        $data = Validator::make($input, (new StoreTransactionRequest)->rules())->validate();
        if ($forced !== null && $data['direction'] !== $forced) {
            $this->invalid('direction', 'This endpoint only records '.$forced.' transactions.');
        }
        if (isset($data['corrects_transaction_id'])) {
            $ctx->authorize(Permission::FinanceReverse);
        }

        return DB::transaction(function () use ($ctx, $data) {
            $this->lockFarm($ctx);
            $hash = RequestHash::of(['operation' => 'record'] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $source = isset($data['source']) ? $this->source($ctx, $data['source'], $data['direction']) : null;
            $cycleId = $this->resolveCycle($ctx, $data['production_cycle_id'] ?? null, $source['cycle_id'] ?? null);
            $contactId = $this->contact($ctx, $data['contact_id'] ?? $source['contact_id'] ?? null);
            $amount = Money::parse($data['amount']);
            if ($source !== null && $source['amount'] !== null && Money::cmp($source['amount'], $amount) !== 0) {
                $this->invalid('amount', 'A purchase is booked for exactly its total ('.$source['amount'].').');
            }
            $category = $this->category($ctx, $data['finance_category_id'] ?? $source['category_id'] ?? null, $data['direction']);
            $today = CarbonImmutable::now($ctx->farm->timezone)->toDateString();
            if ($data['occurred_on'] > $today) {
                $this->invalid('occurred_on', 'A transaction cannot be dated in the future.');
            }
            $recordedAt = isset($data['recorded_at']) ? CarbonImmutable::parse($data['recorded_at'])->utc() : CarbonImmutable::now()->utc();
            if ($recordedAt->isFuture()) {
                $this->invalid('recorded_at', 'The event cannot be in the future.');
            }
            $correction = isset($data['corrects_transaction_id']) ? $this->correctedEntry($ctx, $data) : null;

            return $this->insert($ctx, [
                'entry_type' => FinanceTransaction::ENTRY, 'direction' => $data['direction'], 'finance_category_id' => $category->id, 'amount' => $amount,
                'occurred_on' => $data['occurred_on'], 'recorded_at' => $recordedAt, 'contact_id' => $contactId, 'production_cycle_id' => $cycleId,
                'description' => $data['description'] ?? null, 'corrects_transaction_id' => $correction?->id,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
            ] + $this->sourceColumns($ctx, $data['source'] ?? null));
        }, 3);
    }

    /** Appends a reversal row; the original is never edited. A purchase's expense is reversed by cancelling the purchase. */
    public function reverse(FarmContext $ctx, string $id, array $input): FinanceTransaction
    {
        $ctx->authorize(Permission::FinanceReverse);
        $data = Validator::make($input, (new ReverseTransactionRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            $this->lockFarm($ctx);
            $hash = RequestHash::of(['operation' => 'reverse', 'id' => $id] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $original = FinanceTransaction::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
            if ($original->source_type === 'purchase') {
                throw new ApiHttpException(409, 'reverse_via_purchase', 'This expense belongs to a purchase; cancel the purchase instead.');
            }
            if ($original->source_type === 'payment') {
                throw new ApiHttpException(409, 'reverse_via_payment', 'This income belongs to a customer payment; reverse the payment instead.');
            }
            $when = isset($data['occurred_on']) ? $data['occurred_on'] : CarbonImmutable::now($ctx->farm->timezone)->toDateString();

            return $this->appendReversal($ctx, $original, $data['reason'], $when, $data['idempotency_key'], $hash);
        }, 3);
    }

    // ----------------------------------------------- Phase 14 integration (purchasing)

    /**
     * The expense a purchase books, in the purchase's own transaction (farm and cycle already locked). The unique source key
     * makes a second booking for the same purchase impossible.
     */
    public function bookPurchase(FarmContext $ctx, Purchase $purchase, FinanceCategory $category): FinanceTransaction
    {
        return $this->insert($ctx, [
            'entry_type' => FinanceTransaction::ENTRY, 'direction' => 'expense', 'finance_category_id' => $category->id, 'amount' => $purchase->total_amount,
            'occurred_on' => $purchase->recorded_at->setTimezone($ctx->farm->timezone)->toDateString(), 'recorded_at' => $purchase->recorded_at,
            'contact_id' => $purchase->contact_id, 'production_cycle_id' => $purchase->production_cycle_id,
            'description' => 'Purchase '.$purchase->reference,
            'source_type' => 'purchase', 'source_id' => $purchase->id, 'source_key' => 'purchase:'.$purchase->id,
        ]);
    }

    /** Offsets the expense of a cancelled purchase (no-op when the purchase booked none). */
    public function reversePurchase(FarmContext $ctx, Purchase $purchase, CarbonImmutable $when, string $reason): ?FinanceTransaction
    {
        $original = FinanceTransaction::where('farm_id', $ctx->farm->id)->where('source_type', 'purchase')->where('source_id', $purchase->id)->where('entry_type', FinanceTransaction::ENTRY)->lockForUpdate()->first();
        if (! $original || $original->reversal()->exists()) {
            return null;
        }

        return $this->appendReversal($ctx, $original, $reason, $when->setTimezone($ctx->farm->timezone)->toDateString(), null, null);
    }

    // ----------------------------------------------- Phase 15 integration (payments)

    /**
     * The income ONE customer payment books, in the payment's own transaction (farm and cycle already locked). Cash basis: a sale or an
     * invoice books nothing; only money actually received does. The unique source key makes a second booking for the same payment impossible.
     */
    public function bookPayment(FarmContext $ctx, string $paymentId, string $amount, string $receivedOn, CarbonImmutable $recordedAt, FinanceCategory $category, ?string $contactId, ?string $cycleId, string $description): FinanceTransaction
    {
        return $this->insert($ctx, [
            'entry_type' => FinanceTransaction::ENTRY, 'direction' => 'income', 'finance_category_id' => $category->id, 'amount' => $amount,
            'occurred_on' => $receivedOn, 'recorded_at' => $recordedAt, 'contact_id' => $contactId, 'production_cycle_id' => $cycleId,
            'description' => $description, 'source_type' => 'payment', 'source_id' => $paymentId, 'source_key' => 'payment:'.$paymentId,
        ]);
    }

    /** Offsets the income of a reversed payment. */
    public function reversePayment(FarmContext $ctx, string $transactionId, CarbonImmutable $when, string $reason): FinanceTransaction
    {
        $original = FinanceTransaction::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($transactionId);

        return $this->appendReversal($ctx, $original, $reason, $when->setTimezone($ctx->farm->timezone)->toDateString(), null, null);
    }

    /** The platform expense category for a code (used for derived purchase categories). */
    public function platformCategory(string $code, string $direction = 'expense'): FinanceCategory
    {
        return FinanceCategory::whereNull('farm_id')->where('code', $code)->where('direction', $direction)->firstOrFail();
    }

    /** A category an expense/income may be booked to: platform or this farm's, active, and of the matching direction. */
    public function category(FarmContext $ctx, ?string $id, string $direction, string $field = 'finance_category_id'): FinanceCategory
    {
        if ($id === null) {
            $this->invalid($field, 'Choose a category.');
        }
        $category = FinanceCategory::visibleTo($ctx->farm)->find($id);
        if (! $category) {
            $this->invalid($field, 'Unknown category.');
        }
        if ($category->direction !== $direction) {
            $this->invalid($field, 'This is an '.$category->direction.' category; choose an '.$direction.' category.');
        }
        if (! $category->is_active) {
            throw new ApiHttpException(409, 'category_inactive', 'This category is no longer in use.');
        }

        return $category;
    }

    /** Validates an optional contact of this farm (404 for foreign ids) and returns its id. */
    public function contact(FarmContext $ctx, ?string $id, bool $supplier = false, bool $customer = false): ?string
    {
        if ($id === null) {
            return null;
        }
        $contact = Contact::ofFarm($ctx->farm)->findOrFail($id);
        if (! $contact->is_active) {
            throw new ApiHttpException(409, 'contact_inactive', 'This contact is inactive.');
        }
        if ($supplier && ! $contact->is_supplier) {
            $this->invalid('contact_id', 'This contact is not a supplier.');
        }
        if ($customer && ! $contact->is_customer) {
            $this->invalid('contact_id', 'This contact is not a customer.');
        }

        return $contact->id;
    }

    /** Locks and validates the open cycle a cost/income is allocated to; a source's own cycle must agree. */
    public function resolveCycle(FarmContext $ctx, ?string $requested, ?string $fromSource = null): ?string
    {
        if ($requested !== null && $fromSource !== null && $requested !== $fromSource) {
            $this->invalid('production_cycle_id', 'The allocation must match the production cycle of the linked source.');
        }
        $id = $requested ?? $fromSource;
        if ($id === null) {
            return null;
        }
        $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->findOrFail($id);
        if ($cycle->status !== CycleStatus::Active) {
            throw new ApiHttpException(409, 'cycle_closed', 'Reopen the cycle before allocating money to it.');
        }

        return $cycle->id;
    }

    // -------------------------------------------------------------- helpers

    private function appendReversal(FarmContext $ctx, FinanceTransaction $original, string $reason, string $occurredOn, ?string $key, ?string $hash): FinanceTransaction
    {
        if ($original->entry_type !== FinanceTransaction::ENTRY || $original->reversal()->exists()) {
            throw new ApiHttpException(409, 'transaction_already_reversed', 'This transaction is a reversal or has already been reversed.');
        }
        if ($occurredOn < $original->occurred_on->toDateString() || $occurredOn > CarbonImmutable::now($ctx->farm->timezone)->toDateString()) {
            $this->invalid('occurred_on', 'A reversal is dated between the original transaction and today.');
        }
        if ($original->production_cycle_id !== null) {
            $this->resolveCycle($ctx, $original->production_cycle_id);
        }

        return $this->insert($ctx, [
            'entry_type' => FinanceTransaction::REVERSAL, 'direction' => $original->direction, 'finance_category_id' => $original->finance_category_id,
            'amount' => $original->amount, 'occurred_on' => $occurredOn, 'recorded_at' => CarbonImmutable::now()->utc(),
            'contact_id' => $original->contact_id, 'production_cycle_id' => $original->production_cycle_id, 'description' => $original->description,
            'source_type' => $original->source_type, 'source_id' => $original->source_id, 'reverses_transaction_id' => $original->id, 'reason' => $reason,
            'idempotency_key' => $key, 'request_hash' => $hash,
        ]);
    }

    private function insert(FarmContext $ctx, array $attributes): FinanceTransaction
    {
        $number = FinanceTransaction::where('farm_id', $ctx->farm->id)->count() + 1;
        try {
            $row = FinanceTransaction::create([
                'farm_id' => $ctx->farm->id, 'reference' => 'FIN-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'currency' => $ctx->farm->currency, 'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ] + $attributes);
        } catch (UniqueConstraintViolationException $e) {
            if (isset($attributes['source_key']) && str_contains($e->getMessage(), 'source_key')) {
                throw new ApiHttpException(409, 'finance_already_recorded', 'This source already has a finance transaction.');
            }
            throw $e;
        }
        FinanceTransactionRecorded::dispatch($row);

        return $row->load(self::RELATIONS);
    }

    /**
     * Resolves the source a transaction is linked to. Returns the defaults it supplies (cycle, contact, purchase amount/category).
     *
     * @return array{cycle_id: string|null, contact_id: string|null, amount: string|null, category_id: string|null}
     */
    private function source(FarmContext $ctx, array $source, string $direction): array
    {
        $type = $source['type'];
        $id = $source['id'];
        $live = FinanceTransaction::where('farm_id', $ctx->farm->id)->where('source_type', $type)->where('source_id', $id)->where('entry_type', FinanceTransaction::ENTRY)
            ->whereDoesntHave('reversal')->first();
        if ($live) {
            throw new ApiHttpException(409, 'finance_already_recorded', 'This source already has a finance transaction.', details: ['transaction_id' => $live->id]);
        }
        if ($type === 'operational_record') {
            $record = OperationalRecord::where('farm_id', $ctx->farm->id)->findOrFail($id);
            if ($record->reverses_record_id !== null || $record->reversal()->exists()) {
                throw new ApiHttpException(409, 'source_reversed', 'A reversed record or a reversal cannot be booked to finance.');
            }

            return ['cycle_id' => $record->production_cycle_id, 'contact_id' => null, 'amount' => null, 'category_id' => null];
        }
        if ($type === 'health_record') {
            $record = HealthRecord::where('farm_id', $ctx->farm->id)->findOrFail($id);
            if ($record->reverses_record_id !== null || $record->reversal()->exists()) {
                throw new ApiHttpException(409, 'source_reversed', 'A reversed record or a reversal cannot be booked to finance.');
            }

            return ['cycle_id' => $record->production_cycle_id, 'contact_id' => null, 'amount' => null, 'category_id' => null];
        }
        $purchase = Purchase::where('farm_id', $ctx->farm->id)->findOrFail($id);
        if ($direction !== 'expense') {
            $this->invalid('direction', 'A purchase can only be booked as an expense.');
        }
        if ($purchase->isCancelled()) {
            throw new ApiHttpException(409, 'source_reversed', 'A cancelled purchase cannot be booked to finance.');
        }

        return ['cycle_id' => $purchase->production_cycle_id, 'contact_id' => $purchase->contact_id, 'amount' => $purchase->total_amount, 'category_id' => $purchase->finance_category_id];
    }

    /** @return array{source_type: string|null, source_id: string|null, source_key: string|null} */
    private function sourceColumns(FarmContext $ctx, ?array $source): array
    {
        if ($source === null) {
            return ['source_type' => null, 'source_id' => null, 'source_key' => null];
        }
        // Only the first entry for a source carries the unique key; a re-booking after a reversal does not.
        $first = ! FinanceTransaction::where('farm_id', $ctx->farm->id)->where('source_type', $source['type'])->where('source_id', $source['id'])->exists();

        return ['source_type' => $source['type'], 'source_id' => $source['id'], 'source_key' => $first ? $source['type'].':'.$source['id'] : null];
    }

    private function correctedEntry(FarmContext $ctx, array $data): FinanceTransaction
    {
        $original = FinanceTransaction::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($data['corrects_transaction_id']);
        $source = $data['source'] ?? null;
        if ($original->entry_type !== FinanceTransaction::ENTRY || ! $original->reversal()->exists() || $original->direction !== $data['direction']
            || FinanceTransaction::where('corrects_transaction_id', $original->id)->exists()
            || ($original->source_type !== ($source['type'] ?? null) || $original->source_id !== ($source['id'] ?? null))) {
            throw new ApiHttpException(409, 'invalid_correction', 'Replace a reversed transaction once, with the same direction and source.');
        }

        return $original;
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?FinanceTransaction
    {
        $row = FinanceTransaction::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($row && ! hash_equals((string) $row->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $row?->load(self::RELATIONS);
    }

    private function lockFarm(FarmContext $ctx): void
    {
        Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
