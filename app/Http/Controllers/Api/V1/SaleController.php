<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoices\IssueInvoiceRequest;
use App\Http\Requests\Sales\CancelSaleRequest;
use App\Http\Requests\Sales\ListSalesRequest;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SaleResource;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SaleService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    /**
     * List sales
     *
     * Requires sale.view. Current farm only; newest recorded_at first. payment_status is derived from the live invoice
     * (uninvoiced, unpaid, partially_paid, paid). from/to are inclusive farm-local days.
     *
     * @response array{data: SaleResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListSalesRequest $request, FarmContext $ctx, SaleService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(SaleResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Record a sale
     *
     * Requires sale.create; a correction (corrects_sale_id) additionally needs sale.cancel, and the optional invoice block
     * ("Save & Create Invoice") needs invoice.create. One transaction creates the sale and its lines, ONE Phase 9 stock-out
     * (reason sale, linked to the line) per stock line - produce only, quantities in the Phase 5 components format, packages
     * resolved through the item's own conversion - and ONE population-ledger exit (a livestock_sale record with a negative
     * population_delta, linked to the line) per livestock line. other lines have no physical effect. A sale books NO income and
     * creates NO invoice by itself: income is booked when a payment is received. Amounts are strings with at most two decimals;
     * the total is the exact sum of the line amounts. Any failure (409 insufficient_stock / insufficient_population / lot_expired /
     * item_inactive / cycle_closed, 404 foreign ids) rolls everything back. The required idempotency_key is farm-wide: an
     * identical retry returns the original (201) with no second stock-out, population exit or invoice; a changed payload is 409.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\SaleResource, meta: object, message:string}')]
    #[Response(status: 404, type: 'array{message:string, code:"not_found", request_id:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"insufficient_stock"|"insufficient_population"|"cycle_closed"|"lot_expired"|"item_inactive"|"contact_inactive"|"idempotency_conflict"|"invalid_correction", request_id:string}')]
    public function store(StoreSaleRequest $request, FarmContext $ctx, SaleService $service): JsonResponse
    {
        return ApiResponse::success((new SaleResource($service->create($ctx, $request->validated())))->resolve($request), message: 'Sale recorded.', status: 201);
    }

    /**
     * Show a sale with its lines
     *
     * Requires sale.view. Each stock line names the inventory movement it caused, each livestock line the population record;
     * the live invoice and its balance are summarised.
     *
     * @response array{data: SaleResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, SaleService $service, string $sale): JsonResponse
    {
        return ApiResponse::success((new SaleResource($service->find($ctx, $sale)))->resolve($request));
    }

    /**
     * Cancel a sale without rewriting history
     *
     * Requires sale.cancel. Appends one compensating stock movement per stock line and one compensating population record per
     * livestock line, voids the (unpaid) invoice and marks the sale cancelled. Nothing is deleted. Money is never implied: 409
     * sale_has_payments while payments are live - reverse them first (POST /payments/{payment}/reverse); 409 cycle_closed when a
     * livestock cycle was closed - reopen it first. A cancelled sale can be replaced once through corrects_sale_id.
     */
    #[Response(status: 200, type: 'array{data: \App\Http\Resources\SaleResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"sale_already_cancelled"|"sale_has_payments"|"cycle_closed"|"idempotency_conflict", request_id:string}')]
    public function cancel(CancelSaleRequest $request, FarmContext $ctx, SaleService $service, string $sale): JsonResponse
    {
        return ApiResponse::success((new SaleResource($service->cancel($ctx, $sale, $request->validated())))->resolve($request), message: 'Sale cancelled.');
    }

    /**
     * Issue the invoice for a sale
     *
     * Requires invoice.create. The invoice is a separate customer document that snapshots the customer, the seller and every
     * line when issued. One live invoice per sale (409 invoice_exists; void it to re-issue). Idempotent through idempotency_key.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\InvoiceResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"invoice_exists"|"sale_cancelled"|"idempotency_conflict", request_id:string}')]
    public function invoice(IssueInvoiceRequest $request, FarmContext $ctx, InvoiceService $service, string $sale): JsonResponse
    {
        return ApiResponse::success((new InvoiceResource($service->issue($ctx, $sale, $request->validated())))->resolve($request), message: 'Invoice issued.', status: 201);
    }
}
