<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Services\Notifications\NotificationInbox;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List my notifications
     *
     * Any active farm member. YOUR in-app notifications on the current farm, newest first; nobody else's are ever returned. `meta.unread_count` is the
     * total number of unread notifications (not just this page). Notifications are created by background evaluation of the dashboard insights (high
     * mortality, low stock, expiring stock, overdue work/invoices, medicine withdrawal, breeding and cycle dates), by task reminders for work assigned
     * to you, and by your own export finishing or failing - filtered by your permissions and your notification preferences. Each has a `source` to open.
     * Read state belongs to the notification; reading one never changes the thing it is about.
     *
     * @response array{data: NotificationResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int, unread_count: int}, message: null}
     */
    public function index(ListNotificationsRequest $request, FarmContext $ctx, NotificationInbox $inbox): JsonResponse
    {
        $page = $inbox->list($ctx, $request->validated());

        return ApiResponse::success(NotificationResource::collection($page->getCollection())->resolve($request), [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'unread_count' => $inbox->unreadCount($ctx),
        ]);
    }

    /**
     * Mark a notification read
     *
     * Any active farm member, for YOUR notification only (someone else's, or another farm's, is `404`). Idempotent: reading it again keeps the first `read_at`.
     *
     * @response array{data: NotificationResource, meta: object, message: string}
     */
    public function read(Request $request, FarmContext $ctx, NotificationInbox $inbox, string $notification): JsonResponse
    {
        return ApiResponse::success((new NotificationResource($inbox->markRead($ctx, $notification)))->resolve($request), message: 'Notification marked as read.');
    }

    /**
     * Mark all my notifications read
     *
     * Any active farm member. Marks every unread notification of yours on the current farm as read; returns how many changed.
     *
     * @response array{data: array{updated: int}, meta: object, message: string}
     */
    public function readAll(FarmContext $ctx, NotificationInbox $inbox): JsonResponse
    {
        return ApiResponse::success(['updated' => $inbox->markAllRead($ctx)], message: 'Notifications marked as read.');
    }
}
