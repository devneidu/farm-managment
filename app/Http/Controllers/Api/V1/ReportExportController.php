<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CreateReportExportRequest;
use App\Http\Requests\Reports\ListReportExportsRequest;
use App\Http\Resources\ReportExportResource;
use App\Services\Reports\ExportService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    private const CONFLICTS = 'array{message: string, code: "idempotency_conflict", request_id: string}';

    /**
     * Request a report export
     *
     * Requires report.export, the permissions of the report being exported, and the `data_export` plan feature (403 `feature_not_available`).
     * The export is generated in the background on the database queue: this returns `202` with an export in status `queued`; poll
     * `GET /reports/exports/{id}` (or watch for the `export_ready` / `export_failed` notification) until it is `completed`, then download. The
     * filters are resolved (omitted dates defaulted) and stored with the export, so the file matches the report at the moment of the request.
     * Formats: csv (table only, UTF-8 with BOM), xlsx (table, filters and summary), pdf (landscape A4). The file is private: only the user who
     * requested it, on the same farm, can download it, and access is re-checked when the file is generated and again on every download.
     * Retrying with the same `idempotency_key` returns the same export (`200`); the same key with different content is `409 idempotency_conflict`.
     * Rate limited per user.
     */
    #[Response(status: 202, type: 'array{data: \App\Http\Resources\ReportExportResource, meta: object, message: string}')]
    #[Response(status: 409, type: self::CONFLICTS)]
    public function store(CreateReportExportRequest $request, FarmContext $ctx, ExportService $exports): JsonResponse
    {
        $result = $exports->request($ctx, $request->validated());

        return ApiResponse::success((new ReportExportResource($result['export']))->resolve($request), message: $result['replayed'] ? 'Export already requested.' : 'Export queued.', status: $result['replayed'] ? 200 : 202);
    }

    /**
     * List my exports
     *
     * Requires report.export. Only the exports YOU requested on the current farm, newest first.
     *
     * @response array{data: ReportExportResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: null}
     */
    public function index(ListReportExportsRequest $request, FarmContext $ctx, ExportService $exports): JsonResponse
    {
        $page = $exports->list($ctx, $request->validated());

        return ApiResponse::success(ReportExportResource::collection($page->getCollection())->resolve($request), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    /**
     * Get an export
     *
     * Requires report.export. Status and lifecycle of one of YOUR exports; someone else's (or another farm's) is `404`.
     *
     * @response array{data: ReportExportResource, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, ExportService $exports, string $export): JsonResponse
    {
        return ApiResponse::success((new ReportExportResource($exports->find($ctx, $export)))->resolve($request));
    }

    /**
     * Download an export
     *
     * Requires report.export, the report's own permissions and the `data_export` feature - re-checked now, not only when the export was requested. Only
     * the requesting user can download. Responds with the file (`Content-Disposition: attachment`, `Cache-Control: private, no-store`).
     * `409 export_not_ready` while queued/processing, `409 export_failed` for a failed export, `410 export_expired` once the file has been removed
     * (completed exports are kept for 7 days); request a new export in that case.
     */
    #[Response(status: 200, description: 'The export file (text/csv, .xlsx or application/pdf).', type: 'string')]
    #[Response(status: 409, type: 'array{message: string, code: "export_not_ready"|"export_failed", request_id: string}')]
    #[Response(status: 410, type: 'array{message: string, code: "export_expired", request_id: string}')]
    public function download(FarmContext $ctx, ExportService $exports, string $export): StreamedResponse
    {
        $file = $exports->download($ctx, $export);

        return Storage::disk($file['disk'])->download($file['path'], $file['name'], [
            'Content-Type' => $file['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
