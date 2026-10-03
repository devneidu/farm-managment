<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRunRequest;
use App\Services\Reports\ReportPayload;
use App\Services\Reports\ReportService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * List reports
     *
     * Requires report.view. The report catalogue for the current farm and viewer: only reports whose underlying data the viewer may see
     * are listed (a Farm Worker or Vet never sees finance reports). Per report: `filters` it accepts, `applies_to` (livestock|crop|null) and `relevant`
     * (false when the farm has no such operation or cycle, so a crop-only farm can hide livestock reports), `available` (false with
     * `unavailable_reason: feature_not_available` when the plan lacks `required_feature`) and `exportable` (needs report.export and the
     * data_export plan feature). Reports are read-only derivations of the existing ledgers and records; nothing is stored.
     *
     * @response array{data: array<int, array{code: string, title: string, family: string, description: string, filters: string[], applies_to: 'livestock'|'crop'|null, relevant: bool, available: bool, unavailable_reason: string|null, required_feature: string|null, exportable: bool, export_formats: string[]}>, meta: object, message: null}
     */
    public function index(FarmContext $ctx, ReportService $reports): JsonResponse
    {
        return ApiResponse::success($reports->catalogue($ctx));
    }

    /**
     * Run a report
     *
     * Requires report.view PLUS the permissions of the data the report reads (403 `forbidden` otherwise), and the plan feature of advanced reports
     * where the catalogue says so (403 `feature_not_available`). `from`/`to` are inclusive farm-local days (default: the last 30 days) and
     * `as_of` is a farm-local day for point-in-time reports; day boundaries use the farm timezone, never UTC. The response carries typed
     * `columns`, the page of `rows`, and `summary` with exact totals over ALL rows (money and quantities are exact decimal strings; quantities of
     * different units are never added together). Reversed and cancelled records are excluded or netted as stated in each report's `notes`.
     * An unknown report is 404 `report_not_found`; a production_cycle_id of another farm is the same 422 as an unknown one.
     *
     * @response array{data: array{report: array{code: string, title: string, family: string, description: string}, farm: array{id: string, name: string, currency: string}, filters: object, timezone: string, generated_at: string, columns: array<int, array{key: string, label: string, type: 'string'|'integer'|'decimal'|'money'|'percent'|'date'|'datetime'}>, rows: array<int, array<string, mixed>>, summary: object, notes: string[]}, meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: null}
     */
    #[Response(status: 404, type: 'array{message: string, code: "report_not_found", request_id: string}')]
    public function show(ReportRunRequest $request, FarmContext $ctx, ReportService $reports, string $report): JsonResponse
    {
        $v = $request->validated();
        $payload = ReportPayload::of($ctx, $reports->run($ctx, $report, $v));
        $total = count($payload['rows']);
        $perPage = (int) ($v['per_page'] ?? 100);
        $page = (int) ($v['page'] ?? 1);
        $payload['rows'] = array_slice($payload['rows'], ($page - 1) * $perPage, $perPage);

        return ApiResponse::success($payload, ['current_page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage)), 'total' => $total]);
    }
}
