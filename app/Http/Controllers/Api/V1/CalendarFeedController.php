<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Work\CalendarFeedService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CalendarFeedController extends Controller
{
    /**
     * Phone calendar link
     *
     * Requires task.view. Your own one-way calendar subscription for the current farm: `enabled`, and when on the `https_url` and `webcal_url` to subscribe a phone
     * calendar (Apple Calendar, Google Calendar, Outlook). The link is a secret: anyone who has it can read your tasks, so treat it like a password.
     *
     * @response array{data: array{enabled: bool, include_milestones: bool, https_url: string|null, webcal_url: string|null}, meta: object, message: null}
     */
    public function show(FarmContext $ctx, CalendarFeedService $service): JsonResponse
    {
        return ApiResponse::success($service->show($ctx));
    }

    /**
     * Turn the phone calendar link on, or make a new one
     *
     * Requires task.view. Turns the feed on (201 with a new link). When it is already on, nothing changes unless `regenerate` is true, which issues a new link
     * and stops the old one working at once. `include_milestones` also adds the cycle, breeding and health follow-up milestones you may see.
     */
    public function store(Request $request, FarmContext $ctx, CalendarFeedService $service): JsonResponse
    {
        $input = $request->validate(['regenerate' => ['sometimes', 'boolean'], 'include_milestones' => ['sometimes', 'boolean']]);
        [$feed, $issued] = $service->enable($ctx, $input);

        return ApiResponse::success($feed, message: $issued ? 'Calendar link created.' : 'Calendar link updated.', status: $issued ? 201 : 200);
    }

    /**
     * Turn the phone calendar link off
     *
     * Requires task.view. The link stops working at once (404). Turn it on again for a new link. Idempotent.
     */
    public function destroy(FarmContext $ctx, CalendarFeedService $service): JsonResponse
    {
        return ApiResponse::success($service->disable($ctx), message: 'Calendar link turned off.');
    }

    /**
     * Calendar feed (public)
     *
     * No session: the secret token in the URL is the credential, and it is rate-limited. Returns an iCalendar (text/calendar) feed of that member's visible tasks from 30 days
     * back to 180 days ahead, including coming repeats. All-day when a task has no due time, otherwise timed in the farm's timezone; reminders become alarms; cancelled tasks are
     * left out and completed ones are marked. 404 for an unknown or switched-off token, or when the member no longer has access.
     */
    public function ics(string $token, CalendarFeedService $service): Response
    {
        $body = $service->render($token);
        if ($body === null) {
            abort(404);
        }

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="farm-tasks.ics"',
            'Cache-Control' => 'private, max-age=300', 'X-Robots-Tag' => 'noindex',
        ]);
    }
}
