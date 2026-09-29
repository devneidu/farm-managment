<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FarmRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\InviteMemberRequest;
use App\Http\Resources\InvitationResource;
use App\Services\Team\InvitationService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmInvitationController extends Controller
{
    /**
     * List open invitations
     *
     * Invitations that are neither accepted nor revoked; `status` is `pending` or `expired`. The invitation
     * token is never returned. Requires `team.view`.
     *
     * @response array{data: InvitationResource[], meta: object, message: string|null}
     */
    public function index(Request $request, FarmContext $ctx): JsonResponse
    {
        $invitations = $ctx->farm->invitations()->whereNull('accepted_at')->whereNull('revoked_at')
            ->with('invitedBy')->latest('created_at')->get();

        return ApiResponse::success(InvitationResource::collection($invitations)->resolve($request));
    }

    /**
     * Invite a team member
     *
     * Emails a single-use link (`{FRONTEND_URL}/invitations/accept?token=...`, valid 7 days) to the address.
     * The person signs in or registers with THAT email, then the frontend calls `POST /invitations/accept`.
     * Requires `team.invite`. Owners may invite manager/farm_worker/finance; Managers farm_worker/finance.
     *
     * Errors: `403 forbidden` / `insufficient_role`, `409 already_member` / `invitation_already_pending`,
     * `422` (invalid email/role; `ownership_transfer_unsupported` for `owner`), `429`.
     */
    #[Response(status: 409, description: 'Already a member or invitation already pending', type: 'array{message: string, code: "already_member"|"invitation_already_pending", request_id: string}')]
    #[Response(status: 201, description: 'Invitation created and emailed', type: 'array{data: \App\Http\Resources\InvitationResource, meta: object, message: string}')]
    public function store(InviteMemberRequest $request, FarmContext $ctx, InvitationService $invitations): JsonResponse
    {
        $invitation = $invitations->invite(
            $ctx,
            $request->user(),
            $request->validated('email'),
            FarmRole::from($request->validated('role')),
        );

        return ApiResponse::success((new InvitationResource($invitation->load('invitedBy')))->resolve($request), message: 'Invitation sent.', status: 201);
    }

    /**
     * Resend an invitation
     *
     * Issues a new link with a fresh expiry; the previous link stops working. Requires `team.invite`.
     *
     * Errors: `403`, `404`, `409 invitation_not_pending` (already accepted or revoked), `429`.
     *
     * @response array{data: InvitationResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Invitation already accepted or revoked', type: 'array{message: string, code: "invitation_not_pending", request_id: string}')]
    public function resend(Request $request, FarmContext $ctx, InvitationService $invitations, string $invitation): JsonResponse
    {
        $updated = $invitations->resend($ctx, $request->user(), $this->find($ctx, $invitation));

        return ApiResponse::success((new InvitationResource($updated->load('invitedBy')))->resolve($request), message: 'Invitation resent.');
    }

    /**
     * Revoke an invitation
     *
     * The link stops working immediately. Requires `team.invite`.
     *
     * Errors: `403`, `404`, `409 invitation_not_pending`.
     *
     * @response array{data: InvitationResource, meta: object, message: string|null}
     */
    #[Response(status: 409, description: 'Invitation already accepted or revoked', type: 'array{message: string, code: "invitation_not_pending", request_id: string}')]
    public function destroy(Request $request, FarmContext $ctx, InvitationService $invitations, string $invitation): JsonResponse
    {
        $revoked = $invitations->revoke($ctx, $request->user(), $this->find($ctx, $invitation));

        return ApiResponse::success((new InvitationResource($revoked->load('invitedBy')))->resolve($request), message: 'Invitation revoked.');
    }

    private function find(FarmContext $ctx, string $id)
    {
        return $ctx->farm->invitations()->findOrFail($id);
    }
}
