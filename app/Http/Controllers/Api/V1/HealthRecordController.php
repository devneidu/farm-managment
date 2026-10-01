<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\ListHealthRecordsRequest;
use App\Http\Requests\Health\ListMedicinesRequest;
use App\Http\Requests\Health\ListWithdrawalsRequest;
use App\Http\Requests\Health\ReverseHealthRecordRequest;
use App\Http\Requests\Health\StoreHealthRecordRequest;
use App\Http\Requests\Health\UpdateMedicineProfileRequest;
use App\Http\Resources\HealthRecordResource;
use App\Http\Resources\MedicineResource;
use App\Http\Resources\WithdrawalResource;
use App\Services\Health\HealthQueries;
use App\Services\Health\HealthService;
use App\Services\Health\HealthTypeRegistry;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class HealthRecordController extends Controller
{
    private function page(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    /**
     * List health record types and their field contracts
     *
     * Requires health.view. Types drive the labels, required fields, applicable cycle kinds and whether medicines are required.
     *
     * @response array{data: array<int,array<string,mixed>>, meta: object, message: null}
     */
    public function types(HealthTypeRegistry $types): JsonResponse
    {
        return ApiResponse::success(array_map(fn ($type) => $types->schema($type), array_keys($types->definitions())));
    }

    /**
     * List health records, including reversals
     *
     * Requires health.view. Current farm only; newest recorded_at then UUID first. Dates are farm-local inclusive days.
     *
     * @response array{data: HealthRecordResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListHealthRecordsRequest $request, FarmContext $ctx, HealthQueries $queries): JsonResponse
    {
        $page = $queries->records($ctx, $request->validated());

        return ApiResponse::success(HealthRecordResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * Record a real health event
     *
     * Requires health.create; a correction (corrects_record_id) additionally needs health.reverse. Each medicine line consumes
     * its stock exactly once through the inventory ledger in the same transaction (409 insufficient_stock / lot_expired roll
     * everything back). Quantities use the Phase 5 components format; packages resolve only through the item's own conversion.
     * recorded_at needs an explicit offset and must lie between the cycle start and now; closed cycles are rejected.
     * The required idempotency_key is farm-wide: an identical retry returns the original record (201) without a second deduction,
     * a changed payload is 409. Withdrawal days default from the medicine profile unless the line states them.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\HealthRecordResource, meta: object, message:string}')]
    #[Response(status: 404, type: 'array{message:string, code:"not_found", request_id:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"insufficient_stock"|"lot_expired"|"item_inactive"|"idempotency_conflict"|"invalid_correction", request_id:string}')]
    public function store(StoreHealthRecordRequest $request, FarmContext $ctx, HealthService $service): JsonResponse
    {
        return ApiResponse::success((new HealthRecordResource($service->create($ctx, $request->validated())))->resolve($request), message: 'Health record saved.', status: 201);
    }

    /**
     * Show a health record with its medicine lines
     *
     * Requires health.view. Foreign records return 404.
     *
     * @response array{data: HealthRecordResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, HealthService $service, string $record): JsonResponse
    {
        return ApiResponse::success((new HealthRecordResource($service->find($ctx, $record)))->resolve($request));
    }

    /**
     * Reverse a health record without rewriting history
     *
     * Requires health.reverse. Appends a reversal record and one compensating stock movement per medicine line; its withdrawal
     * windows stop counting. Cannot reverse a reversal or reverse twice. A linked Phase 8 mortality record is NOT reversed here.
     * Correct by reversing, then POST a replacement with corrects_record_id. No PATCH/DELETE exists.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\HealthRecordResource, meta: object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"record_already_reversed"|"idempotency_conflict"|"insufficient_stock", request_id:string}')]
    public function reverse(ReverseHealthRecordRequest $request, FarmContext $ctx, HealthService $service, string $record): JsonResponse
    {
        return ApiResponse::success((new HealthRecordResource($service->reverse($ctx, $record, $request->validated())))->resolve($request), message: 'Reversal recorded.', status: 201);
    }

    /**
     * List withdrawal windows
     *
     * Requires health.view. Each row names the health record and medicine line that started it. Active only by default
     * (ends_at in the future); reversed records never appear.
     *
     * @response array{data: WithdrawalResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function withdrawals(ListWithdrawalsRequest $request, FarmContext $ctx, HealthQueries $queries): JsonResponse
    {
        $page = $queries->withdrawals($ctx, $request->validated());

        return ApiResponse::success(WithdrawalResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * List medicines with derived stock and withdrawal profile
     *
     * Requires health.view. Phase 9 inventory items in the medicine category.
     * Create items, receive stock and configure packaging through the inventory and package-conversion endpoints.
     *
     * @response array{data: MedicineResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function medicines(ListMedicinesRequest $request, FarmContext $ctx, HealthQueries $queries): JsonResponse
    {
        $page = $queries->medicines($ctx, $request->validated());

        return ApiResponse::success(MedicineResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * Show a medicine with stock by location and by lot (expiry)
     *
     * Requires health.view. Foreign items and items of other categories return 404.
     *
     * @response array{data: MedicineResource, meta: object, message: null}
     */
    public function medicine(Request $request, FarmContext $ctx, HealthQueries $queries, string $item): JsonResponse
    {
        return ApiResponse::success((new MedicineResource($queries->medicine($ctx, $item)))->withBalances()->withLots($ctx->farm->timezone)->resolve($request));
    }

    /**
     * Set a medicine's default withdrawal period
     *
     * Requires health.manage. Applies to FUTURE health lines only; each recorded line keeps the days it used.
     */
    public function updateProfile(UpdateMedicineProfileRequest $request, FarmContext $ctx, HealthQueries $queries, string $item): JsonResponse
    {
        return ApiResponse::success((new MedicineResource($queries->updateProfile($ctx, $item, $request->validated())))->resolve($request), message: 'Medicine profile updated.');
    }
}
