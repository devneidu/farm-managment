<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LivestockExits\ListLivestockExitsRequest;
use App\Http\Requests\LivestockExits\StoreLivestockExitRequest;
use App\Http\Requests\Records\ReverseRecordRequest;
use App\Http\Resources\OperationalRecordResource;
use App\Http\Resources\SaleResource;
use App\Services\Records\LivestockExitService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class LivestockExitController extends Controller
{
    /**
     * List animals that left a batch
     *
     * Requires record.view. One list built from the existing records (sales, deaths and the new exits); nothing is copied. Reversed events and
     * reversal rows are left out, and a cancelled sale disappears with its animals. Newest first. Filters: production_cycle_id, type
     * (sold, died, slaughtered, culled, given_away, moved), recorded_from / recorded_to (farm-local inclusive days). `source` says where the
     * event lives: a sale, an operational record or the destination batch of a move; `undo` says how to undo it (`reverse` or `cancel_sale`).
     *
     * @response array{data: array<int, array{id: string, production_cycle_id: string, type: 'sold'|'died'|'slaughtered'|'culled'|'given_away'|'moved', label: string, head_count: int, recorded_at: string, notes: string|null, details: object, source: array{type: 'sale'|'operational_record'|'production_cycle', id: string|null, reference: string|null}, undo: 'reverse'|'cancel_sale', reversed: false}>, meta: array{current_page:int,per_page:int,last_page:int,total:int}, message: null}
     */
    public function index(ListLivestockExitsRequest $request, FarmContext $ctx, LivestockExitService $service): JsonResponse
    {
        $page = $service->list($ctx, $request->validated());

        return ApiResponse::success($page->getCollection()->all(), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Record animals leaving a batch
     *
     * Requires record.create (sold: sale.create instead; slaughtered: inventory.use as well). One request, one idempotency_key for everything it does
     * (a retry returns the original result, a changed body is 409 idempotency_conflict). Every type is one transaction.
     * sold: unit_price plus optional buyer and payment - exactly POST /sales with one livestock line (the response also carries the sale).
     * died: cause (default "Unknown") - the ordinary mortality record. slaughtered: meat_weight_kg (> 0), optional live_weight_kg and storage_location_id -
     * the head count goes down and the meat is received into the farm's "{Species} meat" stock item (kg, created on first use).
     * culled: reason (poor_production, sick_injured, old, other). given_away: optional given_to. moved: destination_cycle_id - an open batch of the same
     * species; the first batch goes down and the second up together.
     * `head_count` in the response is what the batch holds afterwards; `close_batch_suggested` is true when it reached 0.
     *
     * @response 201 array{data: array{type: string, label: string, production_cycle_id: string, head_count: int, close_batch_suggested: bool, event: object, sale: object|null, meat: array{inventory_item_id: string, name: string, quantity_kg: string, storage_location_id: string}|null, destination: array{production_cycle_id: string, reference: string, head_count: int}|null}, meta: object, message: string}
     */
    #[Response(status: 404, type: 'array{message:string, code:"not_found", request_id:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"insufficient_population"|"insufficient_stock"|"idempotency_conflict"|"cycle_reconciliation_failed", request_id:string}')]
    public function store(StoreLivestockExitRequest $request, FarmContext $ctx, LivestockExitService $service): JsonResponse
    {
        $out = $service->create($ctx, $request->validated());
        $result = $out['result'] + ['sale' => $out['sale'] ? (new SaleResource($out['sale']))->resolve($request) : null];

        return ApiResponse::success($result, message: 'Recorded.', status: 201);
    }

    /**
     * Undo an exit
     *
     * Requires record.reverse. `{record}` is the id of a list row. Reason, recorded_at and idempotency_key are required; the date cannot precede the event.
     * died, culled, given_away: the record is reversed and the animals return. slaughtered: also takes the meat back out of stock (409 insufficient_stock if it
     * was since used or sold). moved: both batches are corrected together (409 insufficient_population if the receiving batch has since lost the animals).
     * sold: 409 reverse_via_sale - cancel the sale instead. POST /records/{id}/reverse refuses a moved record with 409 reverse_via_livestock_exit.
     *
     * @response 201 array{data: array{reversed_record_id: string, type: string, reversal: OperationalRecordResource, linked_reversal: OperationalRecordResource|null, head_count: int, close_batch_suggested: bool}, meta: object, message: string}
     */
    #[Response(status: 409, type: 'array{message:string, code:"reverse_via_sale"|"record_already_reversed"|"cycle_closed"|"insufficient_population"|"insufficient_stock"|"idempotency_conflict", request_id:string}')]
    public function reverse(ReverseRecordRequest $request, FarmContext $ctx, LivestockExitService $service, string $record): JsonResponse
    {
        $out = $service->reverse($ctx, $record, $request->validated());
        $out['reversal'] = (new OperationalRecordResource($out['reversal']->load(['reversal', 'attachments', 'inventoryMovement'])))->resolve($request);
        $out['linked_reversal'] = $out['linked_reversal'] ? (new OperationalRecordResource($out['linked_reversal']->load(['reversal', 'attachments', 'inventoryMovement'])))->resolve($request) : null;

        return ApiResponse::success($out, message: 'Reversal recorded.', status: 201);
    }
}
