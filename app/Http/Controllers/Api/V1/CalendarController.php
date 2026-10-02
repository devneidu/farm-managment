<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Work\CalendarRequest;
use App\Http\Resources\TaskResource;
use App\Services\Work\CalendarService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class CalendarController extends Controller
{
    /**
     * Calendar
     *
     * Requires task.view. A read model over a farm-local date range (max 92 days): the caller's visible tasks plus read-only milestones that are
     * not tasks and not records: cycle_start / cycle_expected_end (active cycles), breeding_expected (exact date) or breeding_expected_window (from-to,
     * active projects, from the stored expectation) and health_follow_up (un-reversed follow_up_on). Milestones need the matching view permission and
     * are omitted when filtering by category or assignee. Items are ordered by date, then time. Task items carry the full task (with due_state).
     *
     * @response array{data: array<int, array{kind: 'task'|'milestone', date: string, end_date: string|null, time: string|null, task?: TaskResource, code?: string, title?: string, source?: array{type: string, id: string, reference: string|null}}>, meta: array{from: string, to: string, timezone: string, today: string}, message: null}
     */
    public function __invoke(CalendarRequest $request, FarmContext $ctx, CalendarService $service): JsonResponse
    {
        $result = $service->range($ctx, $request->validated());
        $items = array_map(function (array $i) use ($request) {
            if ($i['kind'] === 'task') {
                $i['task'] = (new TaskResource($i['task']))->resolve($request);
                unset($i['now'], $i['today']);
            }

            return $i;
        }, $result['items']);

        return ApiResponse::success($items, $result['meta']);
    }
}
