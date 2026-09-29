<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FarmRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\UpdateMemberRoleRequest;
use App\Http\Resources\MemberResource;
use App\Models\FarmMembership;
use App\Services\Team\TeamService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamMemberController extends Controller
{
    /**
     * List team members
     *
     * Active members of the current farm, Owner first. Requires `team.view`.
     *
     * @response array{data: MemberResource[], meta: object, message: string|null}
     */
    public function index(Request $request, FarmContext $ctx): JsonResponse
    {
        $members = $ctx->farm->memberships()->active()->with('user')
            ->orderByRaw('role = ? desc', [FarmRole::Owner->value])
            ->orderBy('created_at')->orderBy('id')
            ->get();

        return ApiResponse::success(MemberResource::collection($members)->resolve($request));
    }

    /**
     * Get a team member
     *
     * `{membership}` is the membership id from the list, not the user id. A membership of another
     * farm (or a removed one) is `404`. Requires `team.view`.
     *
     * @response array{data: MemberResource, meta: object, message: string|null}
     */
    public function show(Request $request, FarmContext $ctx, string $membership): JsonResponse
    {
        return ApiResponse::success((new MemberResource($this->find($ctx, $membership)))->resolve($request));
    }

    /**
     * Change a member's role
     *
     * Body: `{ "role": "manager" | "farm_worker" | "finance" }`. Requires `team.update_role`.
     * Owners may set manager/farm_worker/finance; Managers may only manage and assign farm_worker/finance.
     * Nobody can assign `owner`, change their own role, or demote the last owner.
     *
     * Errors: `403 forbidden` (missing permission) / `insufficient_role` / `cannot_change_own_role`,
     * `404`, `409 last_owner`, `422` (invalid role, or `ownership_transfer_unsupported` for `owner`).
     *
     * @response array{data: MemberResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Would leave the farm without an owner', type: 'array{message: string, code: "last_owner", request_id: string}')]
    public function update(UpdateMemberRoleRequest $request, FarmContext $ctx, TeamService $team, string $membership): JsonResponse
    {
        $updated = $team->changeRole(
            $ctx,
            $request->user(),
            $this->find($ctx, $membership),
            FarmRole::from($request->validated('role')),
        );

        return ApiResponse::success((new MemberResource($updated->load('user')))->resolve($request), message: 'Role updated.');
    }

    /**
     * Remove a member
     *
     * Ends the member's access to this farm immediately (their history is kept; they can be re-invited).
     * Requires `team.remove`. The Owner cannot be removed while they are the only owner, and nobody can
     * remove themselves.
     *
     * Errors: `403 forbidden` / `insufficient_role` / `cannot_remove_self`, `404`, `409 last_owner`.
     */
    #[Response(status: 409, description: 'Would leave the farm without an owner', type: 'array{message: string, code: "last_owner", request_id: string}')]
    public function destroy(Request $request, FarmContext $ctx, TeamService $team, string $membership): JsonResponse
    {
        $team->remove($ctx, $request->user(), $this->find($ctx, $membership));

        return ApiResponse::success(message: 'Member removed.');
    }

    /** Scoped lookup: only ACTIVE memberships of the acting farm, so cross-farm ids are indistinguishable from missing. */
    private function find(FarmContext $ctx, string $id): FarmMembership
    {
        return $ctx->farm->memberships()->active()->with('user')->findOrFail($id);
    }
}
