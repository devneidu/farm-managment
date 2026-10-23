<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ListCategoriesRequest;
use App\Http\Requests\Finance\ListTransactionsRequest;
use App\Http\Requests\Finance\ReverseTransactionRequest;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\StoreIncomeRequest;
use App\Http\Requests\Finance\StoreTransactionRequest;
use App\Http\Requests\Finance\SummaryRequest;
use App\Http\Resources\FinanceCategoryResource;
use App\Http\Resources\FinanceTransactionResource;
use App\Services\Finance\FinanceQueries;
use App\Services\Finance\FinanceService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    private const CONFLICTS = 'array{message:string, code:"finance_already_recorded"|"source_reversed"|"cycle_closed"|"contact_inactive"|"category_inactive"|"idempotency_conflict"|"invalid_correction"|"possible_duplicate", request_id:string, details?: array{purchase_id?: string, label?: string, reference?: string}}';

    /**
     * List income and expense categories
     *
     * Requires finance.view. Platform categories (is_system) plus this farm's own, active only. manual_entry is false for livestock/egg/milk/crop sales: hide those on the manual form (POST /finance/transactions refuses them with 422; record the sale through Sales).
     *
     * @response array{data: FinanceCategoryResource[], meta: object, message: null}
     */
    public function categories(ListCategoriesRequest $request, FarmContext $ctx, FinanceService $service): JsonResponse
    {
        return ApiResponse::success(FinanceCategoryResource::collection($service->categories($ctx, $request->validated()))->resolve($request));
    }

    /**
     * List finance transactions
     *
     * Requires finance.view. Current farm only; newest occurred_on first. Reversal rows are included (entry_type=reversal);
     * filter with entry_type=entry to hide them. payment_method filters by how the money moved. Every row has a source object (label, ids to open it) and can_reverse_directly. from/to are inclusive farm-local days on occurred_on.
     *
     * @response array{data: FinanceTransactionResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListTransactionsRequest $request, FarmContext $ctx, FinanceQueries $queries): JsonResponse
    {
        $page = $queries->transactions($ctx, $request->validated());

        return ApiResponse::success(FinanceTransactionResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Record an income or expense transaction
     *
     * Requires finance.create; a correction (corrects_transaction_id) additionally needs finance.reverse. Optional `source`
     * ({type: operational_record|health_record|purchase, id}) is "record as expense/income" for an existing event: there is ONE
     * live transaction per source (409 finance_already_recorded, with the existing transaction_id), and the cycle is taken
     * from the source. A purchase books its own expense, so a manual expense for a purchase is only possible when it was recorded
     * with record_expense=false, for exactly its total. Optional payment_method; optional quantity + unit_price (both or neither, 422 otherwise)
     * from which amount is computed when omitted (422 if a sent amount differs). Product-sales categories are refused with 422. A manual expense that
     * matches a live purchase (same amount, day and contact) returns 409 possible_duplicate; resend with confirm_duplicate=true to record it anyway. Money is never an inventory change. The required idempotency_key is
     * farm-wide: an identical retry returns the original, a changed payload is 409.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\FinanceTransactionResource, meta: object, message:string}')]
    #[Response(status: 409, type: self::CONFLICTS)]
    public function store(StoreTransactionRequest $request, FarmContext $ctx, FinanceService $service): JsonResponse
    {
        return ApiResponse::success((new FinanceTransactionResource($service->record($ctx, $request->validated())))->resolve($request), message: 'Transaction recorded.', status: 201);
    }

    /**
     * Record an expense
     *
     * Same as POST /finance/transactions with direction=expense. Requires finance.create.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\FinanceTransactionResource, meta: object, message:string}')]
    #[Response(status: 409, type: self::CONFLICTS)]
    public function expense(StoreExpenseRequest $request, FarmContext $ctx, FinanceService $service): JsonResponse
    {
        return ApiResponse::success((new FinanceTransactionResource($service->record($ctx, $request->validated(), 'expense')))->resolve($request), message: 'Expense recorded.', status: 201);
    }

    /**
     * Record income
     *
     * Same as POST /finance/transactions with direction=income. Requires finance.create. Product sales (livestock, eggs, milk, crops) are recorded through Sales, not here.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\FinanceTransactionResource, meta: object, message:string}')]
    #[Response(status: 409, type: self::CONFLICTS)]
    public function income(StoreIncomeRequest $request, FarmContext $ctx, FinanceService $service): JsonResponse
    {
        return ApiResponse::success((new FinanceTransactionResource($service->record($ctx, $request->validated(), 'income')))->resolve($request), message: 'Income recorded.', status: 201);
    }

    /**
     * Show a transaction
     *
     * Requires finance.view. Foreign transactions return 404.
     *
     * @response array{data: FinanceTransactionResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, FinanceService $service, string $transaction): JsonResponse
    {
        return ApiResponse::success((new FinanceTransactionResource($service->find($ctx, $transaction)))->resolve($request));
    }

    /**
     * Reverse a transaction without rewriting history
     *
     * Requires finance.reverse. Appends an offsetting reversal row (same amount, category, contact, cycle and source); totals
     * net to zero. Cannot reverse a reversal or reverse twice (409 transaction_already_reversed). An expense booked by a
     * purchase is reversed by cancelling the purchase (409 reverse_via_purchase). Correct by reversing, then POST a replacement
     * with corrects_transaction_id. No PATCH/DELETE exists.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\FinanceTransactionResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"transaction_already_reversed"|"reverse_via_purchase"|"cycle_closed"|"idempotency_conflict", request_id:string}')]
    public function reverse(ReverseTransactionRequest $request, FarmContext $ctx, FinanceService $service, string $transaction): JsonResponse
    {
        return ApiResponse::success((new FinanceTransactionResource($service->reverse($ctx, $transaction, $request->validated())))->resolve($request), message: 'Reversal recorded.', status: 201);
    }

    /**
     * Finance summary and cycle profitability
     *
     * Requires finance.view. Signed totals (reversals offset entries) of income, expense and net, by category and - without a
     * production_cycle_id filter - by cycle (null cycle = unallocated). With production_cycle_id this is that cycle's profitability.
     * Amounts are strings with two decimals.
     *
     * @response array{data: array{currency: string, from: string|null, to: string|null, production_cycle_id: string|null, totals: array{income: string, expense: string, net: string}, by_category: array<int, array{finance_category_id: string, code: string|null, name: string|null, direction: string, amount: string}>, by_cycle?: array<int, array{production_cycle_id: string|null, income: string, expense: string, net: string}>}, meta: object, message: null}
     */
    public function summary(SummaryRequest $request, FarmContext $ctx, FinanceQueries $queries): JsonResponse
    {
        return ApiResponse::success($queries->summary($ctx, $request->validated()));
    }
}
