<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\IssueInvoiceRequest;
use App\Http\Requests\Invoices\ListInvoicesRequest;
use App\Http\Requests\Invoices\VoidInvoiceRequest;
use App\Http\Requests\Payments\RecordPaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\PaymentService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    /**
     * List invoices
     *
     * Requires invoice.view. Current farm only; newest issue_date first. payment_status and overdue are derived from live payments.
     *
     * @response array{data: InvoiceResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListInvoicesRequest $request, FarmContext $ctx, InvoiceService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(InvoiceResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Issue an invoice for a sale
     *
     * Requires invoice.create. Same as POST /sales/{sale}/invoice with sale_id in the body. 409 invoice_exists while the sale has a
     * live invoice; 409 sale_cancelled for a cancelled sale.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\InvoiceResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"invoice_exists"|"sale_cancelled"|"idempotency_conflict", request_id:string}')]
    public function store(IssueInvoiceRequest $request, FarmContext $ctx, InvoiceService $service): JsonResponse
    {
        return ApiResponse::success((new InvoiceResource($service->issue($ctx, null, $request->validated())))->resolve($request), message: 'Invoice issued.', status: 201);
    }

    /**
     * Show an invoice
     *
     * Requires invoice.view. Customer, seller and lines are the snapshot taken at issue time; amount_paid / outstanding / payment_status are live.
     *
     * @response array{data: InvoiceResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, InvoiceService $service, string $invoice): JsonResponse
    {
        return ApiResponse::success((new InvoiceResource($service->find($ctx, $invoice)))->resolve($request));
    }

    /**
     * Download the invoice as a PDF
     *
     * Requires invoice.view. Generated on demand from the invoice snapshot (never from live contact or product data), so a
     * regenerated PDF always matches what was issued. Responds with application/pdf.
     */
    #[Response(status: 200, description: 'The invoice PDF (application/pdf).', type: 'string')]
    public function pdf(FarmContext $ctx, InvoiceService $service, string $invoice): \Illuminate\Http\Response
    {
        $file = $service->pdf($ctx, $invoice);

        return response($file['content'], 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$file['reference'].'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Void an invoice
     *
     * Requires invoice.void. Marks the invoice void (the sale is untouched and can be invoiced again). 409 invoice_has_payments
     * while payments are live - reverse them first. Idempotent through idempotency_key.
     */
    #[Response(status: 200, type: 'array{data: \App\Http\Resources\InvoiceResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"invoice_already_void"|"invoice_has_payments"|"idempotency_conflict", request_id:string}')]
    public function void(VoidInvoiceRequest $request, FarmContext $ctx, InvoiceService $service, string $invoice): JsonResponse
    {
        return ApiResponse::success((new InvoiceResource($service->void($ctx, $invoice, $request->validated())))->resolve($request), message: 'Invoice voided.');
    }

    /**
     * Record a payment against an invoice
     *
     * Requires payment.create. Money actually received: one transaction appends the payment and books ONE income entry in the
     * finance ledger linked to it (the sale and invoice book nothing, so income is never counted twice). Partial payments are
     * normal; the sum of live payments can never exceed the invoice total (409 payment_exceeds_balance, details.outstanding).
     * 409 invoice_void for a void invoice. The income is allocated to the sale's production cycle only when all lines share one
     * open cycle. The required idempotency_key is farm-wide: an identical retry returns the original (201) with no second
     * payment or income entry; a changed payload is 409.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\PaymentResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"payment_exceeds_balance"|"invoice_void"|"category_inactive"|"idempotency_conflict", request_id:string}')]
    public function pay(RecordPaymentRequest $request, FarmContext $ctx, PaymentService $service, string $invoice): JsonResponse
    {
        return ApiResponse::success((new PaymentResource($service->record($ctx, $invoice, $request->validated())))->resolve($request), message: 'Payment recorded.', status: 201);
    }
}
