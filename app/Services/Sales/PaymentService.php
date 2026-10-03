<?php

namespace App\Services\Sales;

use App\Enums\CycleStatus;
use App\Enums\Permission;
use App\Events\Sales\PaymentRecorded;
use App\Http\Requests\Payments\RecordPaymentRequest;
use App\Http\Requests\Payments\ReversePaymentRequest;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProductionCycle;
use App\Services\Finance\FinanceService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Finance\Money;
use App\Support\Idempotency\RequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of payments. A payment is money actually received against an invoice. Recording one is a single transaction that
 * appends the payment row and books ONE income entry in the Phase 14 ledger linked to it (cash basis: the sale and the invoice book
 * nothing, so no income is ever counted twice). Payments are append-only; reversing one appends an offsetting payment row and an
 * offsetting ledger row. A payment can never push the invoice past its total, and multiple partial payments are normal.
 *
 * Lock order matches the rest of the module: farm row, then the production cycle row (if the income is allocated), then the invoice.
 */
class PaymentService
{
    private const RELATIONS = ['reversal', 'transaction.reversal', 'invoice'];

    public function __construct(private FinanceService $finance) {}

    public function find(FarmContext $ctx, string $id): Payment
    {
        $ctx->authorize(Permission::PaymentView);

        return Payment::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::PaymentView);
        $q = Payment::where('farm_id', $ctx->farm->id)->with(self::RELATIONS);
        foreach (['invoice_id', 'method', 'entry_type'] as $column) {
            if (isset($f[$column])) {
                $q->where($column, $f[$column]);
            }
        }
        if (isset($f['contact_id'])) {
            $q->whereIn('invoice_id', Invoice::where('farm_id', $ctx->farm->id)->where('contact_id', $f['contact_id'])->select('id'));
        }
        if (isset($f['from'])) {
            $q->where('received_on', '>=', $f['from']);
        }
        if (isset($f['to'])) {
            $q->where('received_on', '<=', $f['to']);
        }

        return $q->orderByDesc('received_on')->orderByDesc('recorded_at')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    public function record(FarmContext $ctx, string $invoiceId, array $input): Payment
    {
        $ctx->authorize(Permission::PaymentCreate);
        $data = Validator::make($input, (new RecordPaymentRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $invoiceId, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['operation' => 'record', 'invoice_id' => $invoiceId] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $invoice = Invoice::where('farm_id', $ctx->farm->id)->with(['sale.items', 'sale.category'])->findOrFail($invoiceId);
            $cycleId = $this->cycleOf($ctx, $invoice);
            $invoice = Invoice::where('farm_id', $ctx->farm->id)->lockForUpdate()->with(['payments', 'sale.items', 'sale.category'])->findOrFail($invoiceId);
            if ($invoice->isVoid()) {
                throw new ApiHttpException(409, 'invoice_void', 'A void invoice cannot receive payments.');
            }
            $amount = Money::parse($data['amount']);
            $outstanding = $invoice->outstanding();
            if (Money::cmp($amount, $outstanding) > 0) {
                throw new ApiHttpException(409, 'payment_exceeds_balance', 'The payment is more than the amount still owed on this invoice.', details: ['outstanding' => $outstanding, 'amount' => $amount]);
            }
            $today = CarbonImmutable::now($ctx->farm->timezone)->toDateString();
            if ($data['received_on'] > $today) {
                $this->invalid('received_on', 'A payment cannot be dated in the future.');
            }
            if ($data['received_on'] < $invoice->sale->recorded_at->setTimezone($ctx->farm->timezone)->toDateString()) {
                $this->invalid('received_on', 'A payment cannot be dated before the sale.');
            }
            $recordedAt = isset($data['recorded_at']) ? CarbonImmutable::parse($data['recorded_at'])->utc() : CarbonImmutable::now()->utc();
            if ($recordedAt->isFuture()) {
                $this->invalid('recorded_at', 'The event cannot be in the future.');
            }
            $category = isset($data['finance_category_id'])
                ? $this->finance->category($ctx, $data['finance_category_id'], 'income')
                : ($invoice->sale->category ?? $this->finance->platformCategory('other_income', 'income'));

            // One real payment -> exactly one income row: the ledger entry is booked first (its source key is unique per payment), then the payment row links to it.
            $id = (string) Str::uuid7();
            $reference = $this->reference($ctx);
            $transaction = $this->finance->bookPayment($ctx, $id, $amount, $data['received_on'], $recordedAt, $category, $invoice->contact_id, $cycleId, 'Payment '.$reference.' for '.$invoice->reference);
            $payment = (new Payment)->forceFill([
                'id' => $id, 'farm_id' => $ctx->farm->id, 'invoice_id' => $invoice->id, 'reference' => $reference,
                'entry_type' => Payment::PAYMENT, 'amount' => $amount, 'currency' => $ctx->farm->currency, 'method' => $data['method'],
                'payment_reference' => $data['payment_reference'] ?? null, 'received_on' => $data['received_on'], 'recorded_at' => $recordedAt,
                'notes' => $data['notes'] ?? null, 'finance_transaction_id' => $transaction->id, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            $payment->save();
            PaymentRecorded::dispatch($payment);

            return $payment->load(self::RELATIONS);
        }, 3);
    }

    /** Appends an offsetting payment row and an offsetting income row. The invoice balance goes back up; nothing is edited or deleted. */
    public function reverse(FarmContext $ctx, string $id, array $input): Payment
    {
        $ctx->authorize(Permission::PaymentReverse);
        $data = Validator::make($input, (new ReversePaymentRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['operation' => 'reverse', 'payment_id' => $id] + $data);
            if ($replay = $this->replay($ctx, $data['idempotency_key'], $hash)) {
                return $replay;
            }
            $original = Payment::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
            if ($original->entry_type !== Payment::PAYMENT || $original->reversal()->exists()) {
                throw new ApiHttpException(409, 'payment_already_reversed', 'This payment is a reversal or has already been reversed.');
            }
            $when = isset($data['recorded_at']) ? CarbonImmutable::parse($data['recorded_at'])->utc() : CarbonImmutable::now()->utc();
            if ($when->isFuture() || $when->lessThan($original->recorded_at)) {
                $this->invalid('recorded_at', 'A reversal is dated between the payment and now.');
            }
            $transaction = $original->transaction;
            if ($transaction?->production_cycle_id !== null) {
                $this->finance->resolveCycle($ctx, $transaction->production_cycle_id);
            }
            $ledger = $transaction !== null ? $this->finance->reversePayment($ctx, $transaction->id, $when, $data['reason']) : null;
            $reversal = (new Payment)->forceFill([
                'id' => (string) Str::uuid7(), 'farm_id' => $ctx->farm->id, 'invoice_id' => $original->invoice_id, 'reference' => $this->reference($ctx),
                'entry_type' => Payment::REVERSAL, 'amount' => $original->amount, 'currency' => $original->currency, 'method' => $original->method,
                'payment_reference' => $original->payment_reference, 'received_on' => CarbonImmutable::now($ctx->farm->timezone)->toDateString(), 'recorded_at' => $when,
                'reverses_payment_id' => $original->id, 'reason' => $data['reason'], 'finance_transaction_id' => $ledger?->id,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
            $reversal->save();
            $fresh = $reversal;
            PaymentRecorded::dispatch($fresh, 'reversed');

            return $fresh->load(self::RELATIONS);
        }, 3);
    }

    // -------------------------------------------------------------- helpers

    /**
     * The single production cycle the sale's lines belong to, locked, when it is still open; otherwise null. Income is allocated to a
     * cycle only when that is unambiguous - collecting money after a cycle closed must stay possible and never edits a closed cycle.
     */
    private function cycleOf(FarmContext $ctx, Invoice $invoice): ?string
    {
        $ids = $invoice->sale->items->pluck('production_cycle_id')->filter()->unique()->values();
        if ($ids->count() !== 1) {
            return null;
        }
        $cycle = ProductionCycle::ofFarm($ctx->farm)->lockForUpdate()->find($ids->first());

        return $cycle?->status === CycleStatus::Active ? $cycle->id : null;
    }

    private function reference(FarmContext $ctx): string
    {
        return 'PAY-'.now()->format('Y').'-'.str_pad((string) (Payment::where('farm_id', $ctx->farm->id)->count() + 1), 5, '0', STR_PAD_LEFT);
    }

    private function replay(FarmContext $ctx, string $key, string $hash): ?Payment
    {
        $payment = Payment::where('farm_id', $ctx->farm->id)->where('idempotency_key', $key)->first();
        if ($payment && ! hash_equals($payment->request_hash, $hash)) {
            throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
        }

        return $payment?->load(self::RELATIONS);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
