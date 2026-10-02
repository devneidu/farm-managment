<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\CancelPurchaseRequest;
use App\Http\Requests\Purchases\ListPurchasesRequest;
use App\Http\Requests\Purchases\StorePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Services\Finance\PurchaseService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    /**
     * List purchases
     *
     * Requires purchase.view. Current farm only; newest recorded_at first. from/to are inclusive farm-local days.
     *
     * @response array{data: PurchaseResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListPurchasesRequest $request, FarmContext $ctx, PurchaseService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success(PurchaseResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Record a purchase
     *
     * Requires purchase.create; a correction (corrects_purchase_id) additionally needs purchase.cancel. One transaction creates
     * the purchase and its lines, ONE Phase 9 stock-in (reason purchase, linked to the line) per stock line, and - unless
     * record_expense is false - ONE expense of the total linked to the purchase. non_stock lines (services, transport) create no
     * stock movement. Quantities use the Phase 5 components format; packages resolve only through the item's own conversion.
     * Amounts are strings with at most two decimals; the total is the exact sum of line amounts. Any failure (409
     * insufficient/lot_expired/item_inactive/cycle_closed, 404 foreign ids) rolls everything back. The required idempotency_key
     * is farm-wide: an identical retry returns the original (201) with no second stock-in or expense; a changed payload is 409.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\PurchaseResource, meta: object, message:string}')]
    #[Response(status: 404, type: 'array{message:string, code:"not_found", request_id:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"lot_expired"|"item_inactive"|"contact_inactive"|"idempotency_conflict"|"invalid_correction", request_id:string}')]
    public function store(StorePurchaseRequest $request, FarmContext $ctx, PurchaseService $service): JsonResponse
    {
        return ApiResponse::success((new PurchaseResource($service->create($ctx, $request->validated())))->resolve($request), message: 'Purchase recorded.', status: 201);
    }

    /**
     * Show a purchase with its lines
     *
     * Requires purchase.view. Each stock line names the inventory movement it created; the purchase names its expense.
     *
     * @response array{data: PurchaseResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, PurchaseService $service, string $purchase): JsonResponse
    {
        return ApiResponse::success((new PurchaseResource($service->find($ctx, $purchase)))->resolve($request));
    }

    /**
     * Cancel a purchase without rewriting history
     *
     * Requires purchase.cancel. Appends one compensating stock movement per stock line (409 insufficient_stock when the received
     * stock has since been used or moved - fix the stock first) and an offsetting ledger row for the expense, then marks the
     * purchase cancelled. Nothing is deleted. A cancelled purchase can be replaced once through corrects_purchase_id.
     */
    #[Response(status: 200, type: 'array{data: \App\Http\Resources\PurchaseResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"purchase_already_cancelled"|"insufficient_stock"|"cycle_closed"|"idempotency_conflict", request_id:string}')]
    public function cancel(CancelPurchaseRequest $request, FarmContext $ctx, PurchaseService $service, string $purchase): JsonResponse
    {
        return ApiResponse::success((new PurchaseResource($service->cancel($ctx, $purchase, $request->validated())))->resolve($request), message: 'Purchase cancelled.');
    }
}
