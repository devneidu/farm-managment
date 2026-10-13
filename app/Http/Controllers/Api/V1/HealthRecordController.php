<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\ListHealthRecordsRequest;
use App\Http\Requests\Health\ListMedicinesRequest;
use App\Http\Requests\Health\ListWithdrawalsRequest;
use App\Http\Requests\Health\ReverseHealthRecordRequest;
use App\Http\Requests\Health\StoreHealthRecordRequest;
use App\Http\Requests\Health\StoreMedicineRequest;
use App\Http\Requests\Health\UpdateMedicineProfileRequest;
use App\Http\Resources\HealthRecordResource;
use App\Http\Resources\MedicineResource;
use App\Http\Resources\WithdrawalResource;
use App\Services\Health\HealthQueries;
use App\Services\Health\HealthService;
use App\Services\Health\HealthTypeRegistry;
use App\Services\Health\MedicineCatalogueService;
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

    /**
     * Medicine master data: types, primary units, package units and permitted stock reasons
     *
     * Requires health.view. `medicine_types` (antibiotic, vaccine, supplement, dewormer, other), `primary_units` (the stock units a medicine may use),
     * `package_units` (bottle, box, card, sachet, bag, vial) and `reasons.in` / `reasons.out`: the stock movement reasons for medicine. In `reasons.out`,
     * `use` has `manual: false` - treatment consumption is recorded through POST /health-records, and POST /inventory/stock-out rejects `use` and
     * `internal_use` for medicines (422). Manual reductions stay available: donation, damaged, expired, spoiled, lost, disposal, other.
     *
     * @response array{data: array<string, mixed>, meta: object, message: null}
     */
    public function medicineOptions(MedicineCatalogueService $catalogue): JsonResponse
    {
        return ApiResponse::success($catalogue->options());
    }

    /**
     * Create a medicine (item + profile + optional packaging + optional opening stock) in one transaction
     *
     * Requires health.manage AND inventory.manage (plus measurement.manage when `packaging` is sent). Creates the inventory item (category medicine),
     * its type/withdrawal profile, an optional package conversion (`packaging`: 1 package = quantity_per_package of `stock_unit`, defined only for this
     * medicine) and an optional opening balance (`opening_stock`: a stock-in with reason opening_balance through the ledger; components may use the
     * packaging unit, e.g. 5 bottle of 100 ml = 500 ml). No expense, supplier or purchase is created; record a bought-in lot with POST /purchases instead.
     * All-or-nothing. Idempotency: with `opening_stock`, repeating the request with the same `opening_stock.idempotency_key` returns the existing medicine
     * (200, nothing received twice); a different request under that key is 409 idempotency_conflict. Without opening stock a repeat is 409 inventory_item_exists.
     * Returns the full medicine (type, profile, packaging, stock, lots). The existing item and profile endpoints are unchanged.
     *
     * Errors: `403`, `404` (location), `409 inventory_item_exists | idempotency_conflict | conversion_exists`, `422` validation / `lot_required` /
     * `invalid_conversion_ratio` / `conversion_not_configured`, `429`.
     *
     * @response 201 array{data: MedicineResource, meta: object, message: string}
     */
    public function storeMedicine(StoreMedicineRequest $request, FarmContext $ctx, MedicineCatalogueService $catalogue): JsonResponse
    {
        [$item, $created] = $catalogue->create($ctx, $request->user(), $request->validated());

        return ApiResponse::success(
            (new MedicineResource($item))->withBalances()->withLots($ctx->farm->timezone)->resolve($request),
            message: $created ? 'Medicine created.' : 'Medicine already created.',
            status: $created ? 201 : 200,
        );
    }
}
