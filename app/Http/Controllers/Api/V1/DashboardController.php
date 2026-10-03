<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\DashboardCalendarRequest;
use App\Services\Dashboard\DashboardCalendar;
use App\Services\Dashboard\DashboardClock;
use App\Services\Dashboard\DashboardService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dashboard
     *
     * Any active farm member. A read model for the current farm and the current viewer; nothing is stored. Blocks: `farm`, `viewer`, `operations`
     * (selected operations and which of livestock/crop content is relevant), `sections` (which areas the viewer may see), `priority` (up to 3
     * critical/warning insights), `kpis` (only cards that are relevant to the farm's operations or active production AND visible to the viewer's
     * permissions - a crop-only farm gets no egg or mortality cards, a Farm Worker no money cards), `work` (task counts by derived due state and the
     * Today & Overdue / upcoming / recently completed lists, the viewer's visible tasks only; null without task.view), `calendar` (7-day strip; null
     * without task.view), `quick_record`, `production` (active cycles; null without production_cycle.view), `insights` (top 5), `recent_activity`
     * (permission-filtered, reversed/cancelled events left out), `quick_add` and `empty_state` (set when no cycle is active).
     * Day boundaries use the farm timezone; quantities keep their units (never summed across units); money is exact two-decimal strings.
     *
     * @response array{data: array<string, mixed>, meta: object, message: null}
     */
    public function show(Request $request, FarmContext $ctx, DashboardService $service): JsonResponse
    {
        return ApiResponse::success($service->build($ctx, $request));
    }

    /**
     * Dashboard calendar strip
     *
     * Requires task.view. A per-day summary of the Phase 12 calendar for `days` farm-local days from `from`: task counts (open, overdue,
     * due_today, upcoming, completed; cancelled tasks are not counted) for the viewer's visible tasks, plus read-only milestones (cycle dates,
     * breeding expectations, health follow-ups, each needing its own view permission). A window milestone appears on every day it covers.
     *
     * @response array{data: array{from: string, to: string, timezone: string, today: string, days: array<int, array{date: string, is_today: bool, tasks: array{open: int, overdue: int, due_today: int, upcoming: int, completed: int}, milestones: array<int, array{code: string, title: string, date: string, end_date: string|null, source: array{type: string, id: string, reference: string|null}}>}>}, meta: object, message: null}
     */
    public function calendar(DashboardCalendarRequest $request, FarmContext $ctx, DashboardCalendar $calendar): JsonResponse
    {
        $v = $request->validated();

        return ApiResponse::success($calendar->summary($ctx, new DashboardClock($ctx->farm), $v['from'] ?? null, (int) ($v['days'] ?? DashboardCalendar::DEFAULT_DAYS)));
    }
}
