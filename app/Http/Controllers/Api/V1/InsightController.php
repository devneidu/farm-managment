<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ListInsightsRequest;
use App\Services\Dashboard\DashboardService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class InsightController extends Controller
{
    /**
     * List insights
     *
     * Any active farm member; the viewer sees only insights whose underlying data they may view. Insights are deterministic rules over existing
     * farm data - `mortality_threshold` (>= 2 deaths in 7 days and >= 2% of the opening population; critical from 5%), `low_stock`, `lot_expiry`,
     * `overdue_tasks`, `overdue_invoices`, `medicine_withdrawal`, `breeding_due_soon`, `breeding_overdue`, `cycle_past_expected_end`. Each carries
     * a `why` block (rule, measured values, thresholds, window) and a `subject` to open. Ordered critical, warning, info, then rule and subject.
     * Insights are computed on request and never stored.
     *
     * @response array{data: array<int, array{id: string, code: string, severity: 'critical'|'warning'|'info', title: string, message: string, why: array<string, mixed>, subject: array{type: string, id: string, reference: string|null, name: string|null}|null, data: object}>, meta: array{total: int, by_severity: array{critical: int, warning: int, info: int}, today: string, timezone: string}, message: null}
     */
    public function index(ListInsightsRequest $request, FarmContext $ctx, DashboardService $service): JsonResponse
    {
        $result = $service->insightList($ctx, $request->validated());

        return ApiResponse::success($result['items'], $result['meta']);
    }
}
