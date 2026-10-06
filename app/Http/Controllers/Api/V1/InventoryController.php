<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InventoryCategory;
use App\Enums\InventoryMovementType;
use App\Enums\StockInReason;
use App\Enums\StockOutReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\ListItemsRequest;
use App\Http\Requests\Inventory\ListLotsRequest;
use App\Http\Requests\Inventory\ListMovementsRequest;
use App\Http\Requests\Inventory\ReverseMovementRequest;
use App\Http\Requests\Inventory\StockInRequest;
use App\Http\Requests\Inventory\StockOutRequest;
use App\Http\Requests\Inventory\StoreItemRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Http\Requests\Inventory\UpdateItemRequest;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\InventoryLotResource;
use App\Http\Resources\InventoryMovementResource;
use App\Services\Inventory\InventoryQueries;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\OutputStockService;
use App\Services\Inventory\StockReasonCatalogue;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryController extends Controller
{
    private function page(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    /**
     * Inventory option catalogue
     *
     * Requires inventory.view. Item categories, movement types, the stock-in / stock-out reason codes and `reasons`: the human labels and
     * routing the frontend needs. `reasons.by_item_kind` (feed, eggs, milk, general) lists, in display order, the actions a farmer can take for that
     * kind of stock; each entry says whether the reason is accepted on the manual stock endpoints (`manual`), whether it creates an operational
     * record, and the authoritative `route` that records the event (sale -> POST /sales, used for livestock -> POST /records feed_use, put into
     * incubation -> POST /breeding-projects, produced on farm -> POST /records egg_collection|milk). System-only reasons are refused on the manual
     * endpoints (422), so one event is never entered twice. `sellable_categories` are the categories a sale stock line may use.
     *
     * @response array{data: array{categories: array<int, array{code: string, label: string}>, sellable_categories: string[], movement_types: string[], stock_in_reasons: string[], stock_out_reasons: string[], reasons: array{in: array<int, array<string, mixed>>, out: array<int, array<string, mixed>>, by_item_kind: array<string, array{in: array<int, array<string, mixed>>, out: array<int, array<string, mixed>>}>}}, meta: object, message: null}
     */
    public function options(StockReasonCatalogue $reasons): JsonResponse
    {
        return ApiResponse::success([
            'categories' => array_map(fn (InventoryCategory $c) => ['code' => $c->value, 'label' => $c->label()], InventoryCategory::cases()),
            'sellable_categories' => array_map(fn (InventoryCategory $c) => $c->value, InventoryCategory::sellable()),
            'reasons' => $reasons->options(),
            'movement_types' => array_column(InventoryMovementType::cases(), 'value'),
            'stock_in_reasons' => array_column(StockInReason::cases(), 'value'),
            'stock_out_reasons' => array_column(StockOutReason::cases(), 'value'),
        ]);
    }

    /**
     * Egg and milk balances (read-only)
     *
     * Requires inventory.view. The farm's available eggs and milk, derived from the movement ledger - never from production totals
     * (104 collected and 44 available are both true). Nothing is created: a farm that has never recorded eggs or milk gets
     * `exists: false` and a zero balance. `by_storage_location` lists non-zero stores; use the ids to sell, give away or incubate.
     * `inventory_item_id` is the item to configure package conversions on (POST /settings/package-conversions, context_type inventory_item).
     *
     * @response array{data: array{eggs: array<string, mixed>, milk: array<string, mixed>}, meta: object, message: null}
     */
    public function outputBalances(FarmContext $ctx, OutputStockService $outputs): JsonResponse
    {
        return ApiResponse::success($outputs->balances($ctx));
    }

    /**
     * List inventory items with derived stock
     *
     * Requires inventory.view. Current farm only. `stock` is derived from movements. Active items by default.
     *
     * @response array{data: InventoryItemResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function items(ListItemsRequest $request, FarmContext $ctx, InventoryQueries $queries): JsonResponse
    {
        $page = $queries->items($ctx, $request->validated());

        return ApiResponse::success(InventoryItemResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * Create an inventory item
     *
     * Requires inventory.manage. There is no opening quantity here: receive opening stock with POST /inventory/stock-in
     * (reason opening_balance). Stock is never writable on an item.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\InventoryItemResource, meta: object, message: string}')]
    #[Response(status: 409, type: 'array{message:string, code:"inventory_item_exists", request_id:string}')]
    public function storeItem(StoreItemRequest $request, FarmContext $ctx, InventoryService $service): JsonResponse
    {
        return ApiResponse::success((new InventoryItemResource($service->createItem($ctx, $request->validated())))->resolve($request), message: 'Inventory item created.', status: 201);
    }

    /**
     * Show an inventory item with stock by location and lot
     *
     * Requires inventory.view. Foreign items return 404. `balances` lists non-zero (location, lot) buckets.
     *
     * @response array{data: InventoryItemResource, meta: object, message: null}
     */
    public function showItem(Request $request, FarmContext $ctx, InventoryQueries $queries, string $item): JsonResponse
    {
        return ApiResponse::success((new InventoryItemResource($queries->item($ctx, $item)))->withBalances()->resolve($request));
    }

    /**
     * Update item details
     *
     * Requires inventory.manage. Name, description, threshold and activation can change at any time. Category, stock unit and
     * lot/expiry tracking lock after the first movement (409 item_has_movements). Deactivation needs zero stock (409 item_has_stock).
     */
    #[Response(status: 409, type: 'array{message:string, code:"item_has_movements"|"item_has_stock"|"inventory_item_exists", request_id:string}')]
    public function updateItem(UpdateItemRequest $request, FarmContext $ctx, InventoryService $service, string $item): JsonResponse
    {
        return ApiResponse::success((new InventoryItemResource($service->updateItem($ctx, $item, $request->validated())))->resolve($request), message: 'Inventory item updated.');
    }

    /**
     * List stock movements (ledger)
     *
     * Requires inventory.view. Newest recorded_at first. Filter by item, storage location, lot, type, source record and farm-local dates.
     *
     * @response array{data: InventoryMovementResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function movements(ListMovementsRequest $request, FarmContext $ctx, InventoryQueries $queries): JsonResponse
    {
        $page = $queries->movements($ctx, $request->validated());

        return ApiResponse::success(InventoryMovementResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * Movement history of one item
     *
     * Requires inventory.view. Same filters as GET /inventory/movements.
     *
     * @response array{data: InventoryMovementResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function itemMovements(ListMovementsRequest $request, FarmContext $ctx, InventoryQueries $queries, string $item): JsonResponse
    {
        $page = $queries->movements($ctx, $request->validated(), $item);

        return ApiResponse::success(InventoryMovementResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * Show one movement
     *
     * Requires inventory.view. Foreign movements return 404.
     *
     * @response array{data: InventoryMovementResource, meta: object, message: null}
     */
    public function showMovement(Request $request, FarmContext $ctx, InventoryQueries $queries, string $movement): JsonResponse
    {
        return ApiResponse::success((new InventoryMovementResource($queries->movement($ctx, $movement)))->resolve($request));
    }

    /**
     * List lots with stock on hand
     *
     * Requires inventory.view. Lots with zero stock are hidden unless include_empty. Soonest expiry first. Expiry is compared with today in the farm timezone.
     *
     * @response array{data: InventoryLotResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function lots(ListLotsRequest $request, FarmContext $ctx, InventoryQueries $queries): JsonResponse
    {
        $page = $queries->lots($ctx, $request->validated());

        return ApiResponse::success(InventoryLotResource::collection($page->getCollection())->resolve($request), $this->page($page));
    }

    /**
     * Receive stock (stock-in)
     *
     * Requires inventory.manage. Appends a stock_in movement. Reasons accepted here: opening_balance, purchase, donation, aid, received, other, and production
     * ("produced on farm") for stock no record explains, such as home-mixed feed. Produced eggs, milk and crops are NOT stocked here: record the production
     * (POST /records egg_collection | milk | crop_harvest) and the stock-in is written with it, so stock and production cannot disagree (422 on reason production
     * for produce). `returned` is written only by the breeding workflow. This endpoint has no supplier/expense effect (use POST /purchases for that).
     * Send `output: eggs|milk` instead of `inventory_item_id` to stock the farm's own egg / milk item without any item setup; `storage_location_id` is then optional
     * (the only active store, a "Main Store" created on first use when the farm has none, 422 when several stores exist). Donated or purchased eggs/milk never create a
     * production record.
     * `components` are entered parts such as 12 bag + 18 kg; bag/crate/bottle resolve only through the item's own package conversion
     * (POST /settings/package-conversions with context_type inventory_item), otherwise 422 conversion_not_configured.
     * Lot-tracked items need `lot_id` or `lot {code, expires_on}`. The storage location must be active with active ancestors.
     * Required idempotency_key is farm-wide: the same key and payload returns the original movement (201); a changed payload is 409.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\InventoryMovementResource, meta: object, message: string}')]
    #[Response(status: 409, type: 'array{message:string, code:"item_inactive"|"location_inactive"|"lot_expired"|"idempotency_conflict", request_id:string}')]
    public function stockIn(StockInRequest $request, FarmContext $ctx, InventoryService $service): JsonResponse
    {
        return $this->single($request, $service->stockIn($ctx, $request->validated()), 'Stock received.');
    }

    /**
     * Issue stock (stock-out)
     *
     * Requires inventory.use. Appends a negative stock_out movement. Reasons accepted here: donation, internal_use, damaged, spoiled, lost, disposal, expired,
     * wasted, other, and use (legacy "other use"; 422 for feed items - feed used for livestock is a feed_use record). Reasons owned by another workflow are refused with 422 naming the endpoint: sale -> POST /sales,
     * production_use (feed used for livestock) -> POST /records type feed_use, incubation -> POST /breeding-projects, so one event is never entered twice.
     * Send `output: eggs|milk` instead of `inventory_item_id` for the farm's own egg / milk stock (never created here: 409 insufficient_stock when there is none;
     * `storage_location_id` optional when the farm has a single active store). Stock can never go negative:
     * the (item, location, lot) bucket is re-checked chronologically under a farm lock (409 insufficient_stock). Lot-tracked items require lot_id
     * (no automatic FIFO/FEFO). Reason `use` is refused for an expired lot (409 lot_expired); write expired stock off with `expired` or `wasted`.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\InventoryMovementResource, meta: object, message: string}')]
    #[Response(status: 409, type: 'array{message:string, code:"insufficient_stock"|"item_inactive"|"lot_expired"|"idempotency_conflict", request_id:string, details?: object}')]
    public function stockOut(StockOutRequest $request, FarmContext $ctx, InventoryService $service): JsonResponse
    {
        return $this->single($request, $service->stockOut($ctx, $request->validated()), 'Stock issued.');
    }

    /**
     * Reconcile a physical count (adjustment)
     *
     * Requires inventory.adjust. The client sends the `expected` ledger balance and the `counted` physical stock of one (item, location, lot);
     * the server stores counted minus expected as a signed adjustment movement (a zero difference is an audited verification). A stale
     * expectation is 409 stock_changed. A count cannot precede existing movements of the same stock. There is no PATCH on quantity.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\InventoryMovementResource, meta: object, message: string}')]
    #[Response(status: 409, type: 'array{message:string, code:"stock_changed"|"item_inactive"|"insufficient_stock"|"idempotency_conflict", request_id:string, details?: object}')]
    public function adjust(AdjustStockRequest $request, FarmContext $ctx, InventoryService $service): JsonResponse
    {
        return $this->single($request, $service->adjust($ctx, $request->validated()), 'Adjustment recorded.');
    }

    /**
     * Transfer stock between storage locations
     *
     * Requires inventory.manage. One business operation: a transfer_out and a transfer_in movement sharing `transfer_group_id`, written atomically;
     * a retry with the same idempotency_key returns the same pair. The source must hold enough stock; the destination must be active.
     * The same lot arrives at the destination. data.movements is [transfer_out, transfer_in].
     *
     * @response 201 array{data: array{transfer_group_id: string, movements: InventoryMovementResource[]}, meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message:string, code:"insufficient_stock"|"item_inactive"|"location_inactive"|"idempotency_conflict", request_id:string, details?: object}')]
    public function transfer(TransferStockRequest $request, FarmContext $ctx, InventoryService $service): JsonResponse
    {
        $rows = $service->transfer($ctx, $request->validated());

        return ApiResponse::success(['transfer_group_id' => $rows[0]->transfer_group_id, 'movements' => InventoryMovementResource::collection($rows)->resolve($request)], message: 'Stock transferred.', status: 201);
    }

    /**
     * Reverse a movement
     *
     * Requires inventory.adjust. Appends compensating reversal movement(s) (both legs of a transfer together); the original stays visible with
     * `reversed_by_movement_id`. Cannot reverse a reversal, reverse twice (409 movement_already_reversed) or reverse a record-driven effect
     * (409 reverse_via_record: reverse the operational record). A reversal that would make stock negative is 409 insufficient_stock.
     *
     * @response 201 array{data: InventoryMovementResource[], meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message:string, code:"movement_already_reversed"|"reverse_via_record"|"insufficient_stock"|"idempotency_conflict", request_id:string}')]
    public function reverse(ReverseMovementRequest $request, FarmContext $ctx, InventoryService $service, string $movement): JsonResponse
    {
        $rows = $service->reverse($ctx, $movement, $request->validated());

        return ApiResponse::success(InventoryMovementResource::collection($rows)->resolve($request), message: 'Reversal recorded.', status: 201);
    }

    private function single(Request $request, array $rows, string $message): JsonResponse
    {
        return ApiResponse::success((new InventoryMovementResource($rows[0]))->resolve($request), message: $message, status: 201);
    }
}
