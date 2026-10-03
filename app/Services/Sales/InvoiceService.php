<?php

namespace App\Services\Sales;

use App\Enums\Permission;
use App\Events\Sales\InvoiceIssued;
use App\Http\Requests\Invoices\IssueInvoiceRequest;
use App\Http\Requests\Invoices\VoidInvoiceRequest;
use App\Models\Farm;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\Inventory\StockLedger;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use App\Support\Finance\Money;
use App\Support\Idempotency\RequestHash;
use App\Support\Measurement\Decimal;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of invoices. An invoice is the customer-facing DOCUMENT for a sale: it snapshots the customer, the seller and every
 * line when issued (later contact or product edits never change it), has its own lifecycle (issued -> void) and its own balance, and
 * writes no stock, population or ledger row. At most one live invoice exists per sale (a database unique key); a void invoice can be
 * replaced. Voiding is refused while live payments exist - money is only unwound by reversing the payment itself.
 *
 * Every write takes the farm row lock first, so it serialises with sales and payments of the same farm.
 */
class InvoiceService
{
    private const RELATIONS = ['items', 'payments.reversal', 'sale'];

    public function __construct(private StockLedger $ledger) {}

    public function find(FarmContext $ctx, string $id): Invoice
    {
        $ctx->authorize(Permission::InvoiceView);

        return Invoice::where('farm_id', $ctx->farm->id)->with(self::RELATIONS)->findOrFail($id);
    }

    public function list(FarmContext $ctx, array $f): LengthAwarePaginator
    {
        $ctx->authorize(Permission::InvoiceView);
        $q = Invoice::where('farm_id', $ctx->farm->id)->with(self::RELATIONS);
        foreach (['status', 'contact_id', 'sale_id'] as $column) {
            if (isset($f[$column])) {
                $q->where($column, $f[$column]);
            }
        }
        $paid = '(SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.invoice_id = invoices.id AND p.entry_type = \'payment\' AND NOT EXISTS (SELECT 1 FROM payments r WHERE r.reverses_payment_id = p.id))';
        if (isset($f['payment_status'])) {
            match ($f['payment_status']) {
                'unpaid' => $q->whereRaw($paid.' = 0'),
                'paid' => $q->whereRaw($paid.' >= invoices.total_amount'),
                'partially_paid' => $q->whereRaw($paid.' > 0 AND '.$paid.' < invoices.total_amount'),
            };
        }
        if (filter_var($f['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $q->where('status', Invoice::ISSUED)->whereNotNull('due_date')->where('due_date', '<', CarbonImmutable::now($ctx->farm->timezone)->toDateString())->whereRaw($paid.' < invoices.total_amount');
        }
        if (! empty($f['search'])) {
            $term = '%'.addcslashes($f['search'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('reference', 'like', $term)->orWhere('customer_name', 'like', $term));
        }
        if (isset($f['from'])) {
            $q->where('issue_date', '>=', $f['from']);
        }
        if (isset($f['to'])) {
            $q->where('issue_date', '<=', $f['to']);
        }

        return $q->orderByDesc('issue_date')->orderByDesc('id')->paginate($f['per_page'] ?? 50);
    }

    /** POST /sales/{sale}/invoice and POST /invoices. */
    public function issue(FarmContext $ctx, ?string $saleId, array $input): Invoice
    {
        $ctx->authorize(Permission::InvoiceCreate);
        $rules = (new IssueInvoiceRequest)->rules();
        if ($saleId !== null) {
            unset($rules['sale_id']);
        }
        $data = Validator::make($input, $rules)->validate();
        $saleId ??= $data['sale_id'];
        unset($data['sale_id']);

        return DB::transaction(function () use ($ctx, $saleId, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['operation' => 'issue', 'sale_id' => $saleId] + $data);
            $existing = Invoice::where('farm_id', $ctx->farm->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $existing->load(self::RELATIONS);
            }
            $sale = Sale::where('farm_id', $ctx->farm->id)->lockForUpdate()->with(['items.item.stockUnit', 'contact'])->findOrFail($saleId);

            return $this->issueFor($ctx, $sale, $data, $data['idempotency_key'], $hash);
        }, 3);
    }

    /**
     * Issues the invoice for a locked sale inside the caller's transaction (also used by "Save & Create Invoice").
     *
     * @param  array{issue_date?: string|null, due_date?: string|null, notes?: string|null}  $data
     */
    public function issueFor(FarmContext $ctx, Sale $sale, array $data, ?string $key, ?string $hash): Invoice
    {
        if ($sale->isCancelled()) {
            throw new ApiHttpException(409, 'sale_cancelled', 'A cancelled sale cannot be invoiced.');
        }
        if (Invoice::where('sale_id', $sale->id)->where('status', Invoice::ISSUED)->exists()) {
            throw new ApiHttpException(409, 'invoice_exists', 'This sale already has a live invoice; void it before issuing another.');
        }
        $today = CarbonImmutable::now($ctx->farm->timezone)->toDateString();
        $issued = $data['issue_date'] ?? $today;
        if ($issued > $today || $issued < $sale->recorded_at->setTimezone($ctx->farm->timezone)->toDateString()) {
            $this->invalid('issue_date', 'An invoice is dated between the sale and today.');
        }
        $due = $data['due_date'] ?? null;
        if ($due !== null && $due < $issued) {
            $this->invalid('due_date', 'The due date cannot precede the issue date.');
        }
        $sale->loadMissing(['items.item.stockUnit', 'contact']);
        $contact = $sale->contact;
        $number = Invoice::where('farm_id', $ctx->farm->id)->count() + 1;
        try {
            $invoice = Invoice::create([
                'farm_id' => $ctx->farm->id, 'sale_id' => $sale->id, 'reference' => 'INV-'.now()->format('Y').'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'status' => Invoice::ISSUED, 'live_sale_key' => $sale->id, 'issue_date' => $issued, 'due_date' => $due, 'contact_id' => $contact?->id,
                'customer_name' => $contact?->name ?? $sale->customer_name, 'customer_phone' => $contact?->phone, 'customer_email' => $contact?->email, 'customer_address' => $contact?->address,
                'seller_name' => $ctx->farm->name, 'total_amount' => $sale->total_amount, 'currency' => $sale->currency, 'notes' => $data['notes'] ?? null,
                'idempotency_key' => $key, 'request_hash' => $hash, 'created_by' => $ctx->membership->user_id, 'request_id' => request()->attributes->get('request_id'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'live_sale_key')) {
                throw new ApiHttpException(409, 'invoice_exists', 'This sale already has a live invoice; void it before issuing another.');
            }
            throw $e;
        }
        foreach ($sale->items as $line) {
            InvoiceItem::create([
                'farm_id' => $ctx->farm->id, 'invoice_id' => $invoice->id, 'line_no' => $line->line_no, 'sale_item_id' => $line->id, 'kind' => $line->kind,
                'description' => $line->description, 'quantity_label' => $this->quantityLabel($line), 'amount' => $line->amount,
            ]);
        }
        InvoiceIssued::dispatch($invoice);

        return $invoice->load(self::RELATIONS);
    }

    /** Voids an issued invoice. Refused while live payments exist: reverse those first so money stays explicit. */
    public function void(FarmContext $ctx, string $id, array $input): Invoice
    {
        $ctx->authorize(Permission::InvoiceVoid);
        $data = Validator::make($input, (new VoidInvoiceRequest)->rules())->validate();

        return DB::transaction(function () use ($ctx, $id, $data) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $hash = RequestHash::of(['void' => $id] + $data);
            $existing = Invoice::where('farm_id', $ctx->farm->id)->where('void_idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if (! hash_equals((string) $existing->void_request_hash, $hash)) {
                    throw new ApiHttpException(409, 'idempotency_conflict', 'This idempotency key was already used for a different request.');
                }

                return $existing->load(self::RELATIONS);
            }
            $invoice = Invoice::where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);

            return $this->voidLocked($ctx, $invoice, $data['reason'], $data['idempotency_key'], $hash);
        }, 3);
    }

    /** Caller holds the farm lock. Used by the sale cancellation as well. */
    public function voidLocked(FarmContext $ctx, Invoice $invoice, string $reason, ?string $key, ?string $hash): Invoice
    {
        if ($invoice->isVoid()) {
            throw new ApiHttpException(409, 'invoice_already_void', 'This invoice is already void.');
        }
        $paid = $invoice->amountPaid();
        if (! Money::isZero($paid)) {
            throw new ApiHttpException(409, 'invoice_has_payments', 'Reverse the payments received on this invoice before voiding it.', details: ['amount_paid' => $paid]);
        }
        $invoice->update([
            'status' => Invoice::VOID, 'live_sale_key' => null, 'voided_at' => CarbonImmutable::now()->utc(), 'void_reason' => $reason, 'voided_by' => $ctx->membership->user_id,
            'void_idempotency_key' => $key, 'void_request_hash' => $hash,
        ]);

        return $invoice->load(self::RELATIONS);
    }

    /** The invoice rendered from its OWN snapshot (never live contact/product data) plus the live payment status. */
    public function pdf(FarmContext $ctx, string $id): array
    {
        $invoice = $this->find($ctx, $id);
        $ctx->authorize(Permission::InvoiceView);
        $html = View::make('invoices.pdf', [
            'invoice' => $invoice, 'paid' => $invoice->amountPaid(), 'outstanding' => $invoice->isVoid() ? '0.00' : $invoice->outstanding(),
            'status' => $invoice->isVoid() ? 'void' : $invoice->paymentStatus(),
            'payments' => $invoice->payments->where('entry_type', Payment::PAYMENT)->filter(fn ($p) => $p->reversal === null)->values(),
            'money' => fn (string $a) => Money::format($a),
        ])->render();
        $tmp = storage_path('app/dompdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('tempDir', $tmp);
        $options->set('fontCache', $tmp);
        $options->set('chroot', [resource_path(), $tmp]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return ['reference' => $invoice->reference, 'content' => $pdf->output()];
    }

    // -------------------------------------------------------------- helpers

    private function quantityLabel($line): ?string
    {
        if ($line->kind === 'livestock') {
            return $line->head_count.' head';
        }
        if ($line->kind === 'stock' && $line->quantity !== null) {
            $shown = $this->ledger->display($line->item, Decimal::trim((string) $line->quantity));

            return $shown['quantity'].' '.$shown['unit'];
        }

        return null;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
