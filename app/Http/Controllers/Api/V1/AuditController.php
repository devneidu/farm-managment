<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audit\ListAuditRequest;
use App\Services\Audit\AuditQueries;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuditController extends Controller
{
    /**
     * Audit trail
     *
     * Requires audit.view (Owner and Manager). A read-only timeline for the current farm of who did what, when and to which resource, newest first.
     * It is assembled from the authoritative append-only records (operational and health records, inventory movements, finance transactions,
     * purchases, sales, invoices, payments, breeding projects/outcomes, task completions, production-cycle events, subscription events) plus an
     * audit log of privileged actions (`team.*`, `farm.settings_updated`, `report.export_requested`, `report.export_downloaded`). A source is only
     * included when the viewer may also view that module. Reversals and corrections are first-class entries (`*.reversed`, `*.corrected`) whose
     * `resource` is the original record and whose `related_id` is the reversing/correcting row. `performed_at` is when the action happened in the
     * system; `recorded_at` is the business time the event was recorded as (kept separate). `request_id` ties an entry to the API request that made
     * it (also returned as `X-Request-Id`). Amounts, record contents and secrets are never part of an entry. `from`/`to` are farm-local days.
     *
     * @response array{data: array<int, array{id: string, action: string, resource: array{type: string, id: string|null, label: string|null}, actor: array{id: string, name: string|null}|null, performed_at: string|null, recorded_at: string|null, changes: object|null, request_id: string|null, ip_address: string|null, related_id: string|null, production_cycle: array{id: string, reference: string, name: string}|null}>, meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: null}
     */
    public function index(ListAuditRequest $request, FarmContext $ctx, AuditQueries $audit): JsonResponse
    {
        $page = $audit->list($ctx, $request->validated());

        return ApiResponse::success($page->items(), ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }
}
