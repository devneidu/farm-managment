<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlaceKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Records\AttachRecordRequest;
use App\Http\Requests\Records\ListRecordsRequest;
use App\Http\Requests\Records\ReverseRecordRequest;
use App\Http\Requests\Records\StoreRecordRequest;
use App\Http\Resources\OperationalRecordResource;
use App\Http\Resources\RecordAttachmentResource;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Services\Locations\PlaceService;
use App\Services\Records\RecordAttachmentService;
use App\Services\Records\RecordService;
use App\Services\Records\RecordTypeRegistry;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalRecordController extends Controller
{
    /**
     * List operational records, including reversals
     *
     * Requires record.view. Current farm only; newest recorded_at then UUID first. Dates are farm-local inclusive days.
     *
     * @response array{data: OperationalRecordResource[], meta: array{current_page:int,per_page:int,last_page:int,total:int}, message:null}
     */
    public function index(ListRecordsRequest $request, FarmContext $ctx): JsonResponse
    {
        $data = $request->validated();
        $q = OperationalRecord::where('farm_id', $ctx->farm->id)->with(['reversal', 'attachments', 'inventoryMovement']);
        if (isset($data['production_cycle_id'])) {
            ProductionCycle::ofFarm($ctx->farm)->findOrFail($data['production_cycle_id']);
            $q->where('production_cycle_id', $data['production_cycle_id']);
        }
        if (isset($data['production_area_id'])) {
            // Plot scope: records of every project sited on this production area (foreign areas are 404).
            app(PlaceService::class)->find($ctx->farm, PlaceKind::ProductionArea, $data['production_area_id']);
            $q->whereIn('production_cycle_id', ProductionCycle::where('farm_id', $ctx->farm->id)->where('production_area_id', $data['production_area_id'])->select('id'));
        }
        if (isset($data['type'])) {
            $q->where('type', $data['type']);
        }
        if (isset($data['recorded_from'])) {
            $q->where('recorded_at', '>=', CarbonImmutable::parse($data['recorded_from'], $ctx->farm->timezone)->startOfDay()->utc());
        }
        if (isset($data['recorded_to'])) {
            $q->where('recorded_at', '<', CarbonImmutable::parse($data['recorded_to'], $ctx->farm->timezone)->addDay()->startOfDay()->utc());
        }
        $page = $q->orderByDesc('recorded_at')->orderByDesc('id')->paginate($data['per_page'] ?? 50);

        return ApiResponse::success(OperationalRecordResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Record a real farm event
     *
     * Requires record.create; population_adjustment additionally record.adjust, correction links record.reverse.
     * See /record-types/{type}/schema for strictly allowed details; unknown detail keys fail 422.
     * recorded_at requires an explicit offset, cycle start <= event <= now. Closed cycles reject new records.
     * Required idempotency_key is farm-wide: exact validated payload replay returns the original record (201), changed payload 409.
     * Measurements use Phase 5 components/context; feed_use and the Phase 13 crop types (fertilizer, pesticide, planting, harvest) may link stock via details.inventory (see Phase 9 and Phase 13).
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\OperationalRecordResource, meta: object, message:string}')]
    #[Response(status: 404, type: 'array{message:string, code:"not_found", request_id:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"insufficient_population"|"population_changed"|"idempotency_conflict"|"invalid_correction"|"cycle_reconciliation_failed", request_id:string}')]
    public function store(StoreRecordRequest $request, FarmContext $ctx, RecordService $service): JsonResponse
    {
        return ApiResponse::success((new OperationalRecordResource($service->create($ctx, $request->validated())))->resolve($request), message: 'Operational record saved.', status: 201);
    }

    /** Show a record and immutable evidence. Requires record.view. Foreign records return 404.
     * @response array{data: OperationalRecordResource, meta:object, message:null}
     */
    public function show(Request $request, FarmContext $ctx, RecordService $service, string $record): JsonResponse
    {
        return ApiResponse::success((new OperationalRecordResource($service->find($ctx, $record)))->resolve($request));
    }

    /** Reverse a record without rewriting history
     *
     * Requires record.reverse. Reopen closed cycles first. Reason and retry key required; reversal date >= original date.
     * Appends an opposite movement; cannot reverse a reversal, reverse twice, or produce negative population.
     * Correct by reversing, then POST a replacement with corrects_record_id. No PATCH/DELETE exists.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\OperationalRecordResource, meta:object, message:string}')]
    #[Response(status: 409, type: 'array{message:string, code:"cycle_closed"|"record_already_reversed"|"insufficient_population"|"idempotency_conflict"|"cycle_reconciliation_failed", request_id:string}')]
    public function reverse(ReverseRecordRequest $request, FarmContext $ctx, RecordService $service, string $record): JsonResponse
    {
        return ApiResponse::success((new OperationalRecordResource($service->reverse($ctx, $record, $request->validated())))->resolve($request), message: 'Reversal recorded.', status: 201);
    }

    /** List implemented record types and their executable field schemas. Requires record.view.
     * @response array{data: array<int,array<string,mixed>>,meta:object,message:null}
     */
    public function types(RecordTypeRegistry $types): JsonResponse
    {
        return ApiResponse::success(array_map(fn ($type) => $types->schema($type), array_keys($types->definitions())));
    }

    /** Get the type-specific detail contract. Requires record.view. Unknown types return 404.
     * @response array{data:array<string,mixed>,meta:object,message:null}
     */
    public function schema(RecordTypeRegistry $types, string $type): JsonResponse
    {
        return ApiResponse::success($types->schema($type));
    }

    /** Upload private evidence (multipart file). Requires record.create, active cycle.
     *
     * Default max 10 MiB and 10 files/record; JPEG, PNG, WebP, PDF, CSV, TXT, XLS, XLSX. Configurable in config/records.php.
     * Signature and extension validation; duplicate file hash returns original attachment. No public storage URL.
     */
    #[Response(status: 201, type: 'array{data: \App\Http\Resources\RecordAttachmentResource,meta:object,message:string}')]
    #[Response(status: 409, type: 'array{message:string,code:"cycle_closed"|"attachment_limit_reached",request_id:string}')]
    public function attach(AttachRecordRequest $request, FarmContext $ctx, RecordAttachmentService $service, string $record): JsonResponse
    {
        return ApiResponse::success((new RecordAttachmentResource($service->attach($ctx, $record, $request->file('file'))))->resolve($request), message: 'Evidence attached.', status: 201);
    }

    /** Download private evidence as an attachment. Requires record.view; farm and parent record scoped. */
    #[Response(status: 200, mediaType: 'application/octet-stream', type: 'string', format: 'binary')]
    public function download(FarmContext $ctx, RecordService $service, string $record, string $attachment): StreamedResponse
    {
        $file = $service->find($ctx, $record)->attachments()->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
