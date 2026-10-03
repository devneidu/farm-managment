<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ChangeFarmPlanRequest;
use App\Http\Requests\Platform\ListFarmsRequest;
use App\Http\Requests\Platform\ListPlatformAuditRequest;
use App\Http\Requests\Platform\ListUsersRequest;
use App\Http\Requests\Platform\SuspendUserRequest;
use App\Http\Resources\Platform\PlatformFarmResource;
use App\Http\Resources\Platform\PlatformUserResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Farm;
use App\Models\Plan;
use App\Services\Platform\PlatformPlanService;
use App\Services\Platform\PlatformSupportService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-farm support tools. These are READ MODELS over accounts, farms, memberships and plans; no farm business data (population, stock,
 * finance, sales, health, breeding) is exposed or editable. Reads: any platform role. Writes: role `admin`, audited.
 */
class PlatformSupportController extends Controller
{
    use Paginates;

    /**
     * List users
     *
     * Search accounts across the platform by `q` (name/email) with `status`, `platform_role`, `verified` filters. Newest first.
     *
     * @response array{data: PlatformUserResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function users(ListUsersRequest $request, PlatformSupportService $support): JsonResponse
    {
        $page = $support->users($request->validated());

        return $this->page($page, PlatformUserResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a user
     *
     * Account status plus every farm membership (farm, role, status). Never includes password hash, tokens, codes or provider ids.
     *
     * @response array{data: PlatformUserResource, meta: object, message: string|null}
     */
    public function user(Request $request, string $user, PlatformSupportService $support): JsonResponse
    {
        return ApiResponse::success((new PlatformUserResource($support->user($user)))->resolve($request));
    }

    /**
     * Suspend a user
     *
     * Blocks sign-in everywhere (existing sessions end on their next request, API tokens are revoked immediately). Reversible with restore.
     * Required `reason` is kept in the audit trail. `409`: `cannot_suspend_self`, `platform_admin_protected`, `already_suspended`.
     * Audited as `platform.user_suspended`. Farm data and memberships are untouched.
     *
     * @response array{data: PlatformUserResource, meta: object, message: string|null}
     */
    public function suspend(SuspendUserRequest $request, string $user, PlatformSupportService $support): JsonResponse
    {
        return ApiResponse::success((new PlatformUserResource($support->suspend($request->user(), $user, $request->validated('reason'))))->resolve($request));
    }

    /**
     * Restore a user
     *
     * `409 not_suspended` when the account is not suspended. Audited as `platform.user_restored`.
     *
     * @response array{data: PlatformUserResource, meta: object, message: string|null}
     */
    public function restore(Request $request, string $user, PlatformSupportService $support): JsonResponse
    {
        return ApiResponse::success((new PlatformUserResource($support->restore($request->user(), $user)))->resolve($request));
    }

    /**
     * List farms
     *
     * Search farms across the platform by `q` (farm name or a member name/email) with `plan` (slug) and `subscription_status` filters;
     * sort by `name` or `created_at`. Each row shows owner, plan, subscription status and team size only.
     *
     * @response array{data: PlatformFarmResource[], meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function farms(ListFarmsRequest $request, PlatformSupportService $support): JsonResponse
    {
        $page = $support->farms($request->validated());

        return $this->page($page, PlatformFarmResource::collection($page->getCollection())->resolve($request));
    }

    /**
     * Show a farm
     *
     * Support overview: farm identity, team (members with roles and status), selected operations, effective plan entitlements with current
     * usage against each limit, and recent subscription events. Contains no farm business records or totals.
     *
     * @response array{data: array<string, mixed>, meta: object, message: string|null}
     */
    public function farm(Request $request, string $farm, PlatformSupportService $support): JsonResponse
    {
        $detail = $support->farm($farm);

        return ApiResponse::success((new PlatformFarmResource($detail['farm']))->resolve($request) + [
            'members' => $detail['members']->map(fn ($m) => ['membership_id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->user?->name, 'email' => $m->user?->email, 'role' => $m->role->value, 'status' => $m->status->value])->values()->all(),
            'operations' => $detail['operations'],
            'entitlements' => $detail['entitlements'],
            'subscription_events' => $detail['subscription_events']->map(fn ($e) => ['type' => $e->type->value, 'from_plan_id' => $e->from_plan_id, 'to_plan_id' => $e->to_plan_id, 'from_status' => $e->from_status, 'to_status' => $e->to_status, 'actor_user_id' => $e->actor_user_id, 'occurred_at' => $e->occurred_at?->toIso8601String()])->values()->all(),
        ]);
    }

    /**
     * Change a farm's plan
     *
     * Support/complimentary plan change through the same subscription service billing will use (the event is recorded with this admin as the
     * actor). No payment is taken. `plan_id` must be an active plan (`422 plan_not_available`); `409 plan_unchanged` when the farm is already
     * on it. A paid plan starts a period of `interval` (default monthly). Required `reason` is kept in the audit trail
     * (`platform.farm_plan_changed`). Existing farm data is never removed; limits only block further growth.
     *
     * @response array{data: SubscriptionResource, meta: object, message: string|null}
     */
    public function changePlan(ChangeFarmPlanRequest $request, Farm $farm, PlatformPlanService $plans): JsonResponse
    {
        $data = $request->validated();
        $subscription = $plans->changeFarmPlan($request->user(), $farm, Plan::findOrFail($data['plan_id']), $data['interval'] ?? null, $data['reason']);

        return ApiResponse::success((new SubscriptionResource($subscription))->resolve($request));
    }

    /**
     * Platform audit trail
     *
     * Newest first. Entries are platform-admin actions (`platform.*`) with actor, action, affected resource, timestamp, request id and the safe
     * before/after facts of the change. They belong to no farm and never appear in a farm's own audit view. Secrets are never recorded.
     *
     * @response array{data: array<int, array{id: string, action: string, resource: array{type: string, id: string|null, label: string|null}, actor: array{id: string, name: string|null}|null, changes: object|null, request_id: string|null, ip_address: string|null, performed_at: string}>, meta: array{current_page: int, per_page: int, last_page: int, total: int}, message: string|null}
     */
    public function audit(ListPlatformAuditRequest $request, PlatformSupportService $support): JsonResponse
    {
        $page = $support->audit($request->validated());

        return $this->page($page, $page->getCollection()->map(fn ($e) => [
            'id' => $e->id, 'action' => $e->action,
            'resource' => ['type' => $e->resource_type, 'id' => $e->resource_id, 'label' => $e->resource_label],
            'actor' => $e->actor_id ? ['id' => $e->actor_id, 'name' => $e->actor_name] : null,
            'changes' => (object) ($e->changes ?? []), 'request_id' => $e->request_id, 'ip_address' => $e->ip_address,
            'performed_at' => $e->created_at->toIso8601String(),
        ])->values()->all());
    }
}
