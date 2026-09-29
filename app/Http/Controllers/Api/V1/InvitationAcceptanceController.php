<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Auth\RespondsWithAuthState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\AcceptInvitationRequest;
use App\Services\Team\InvitationService;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class InvitationAcceptanceController extends Controller
{
    use RespondsWithAuthState;

    /**
     * Accept a farm invitation
     *
     * Body: `{ "token": "<token from the emailed link>" }`. The caller must be signed in with a VERIFIED
     * account whose email equals the invited email. Creates (or re-activates) the membership with the invited
     * role; a user who has not set up a farm yet skips farm setup (`next_action = "none"`). The response is the
     * auth state; its `farm` is your default farm - send `X-Farm-Id` with `meta.accepted_farm_id` to work on the newly joined farm if you already belong to another one.
     *
     * Errors: `401`, `403 email_verification_required` / `invitation_email_mismatch`, `404 invitation_not_found`,
     * `409 already_member`, `410 invitation_used` / `invitation_revoked` / `invitation_expired`, `422`, `429`.
     */
    #[Response(status: 410, description: 'Invitation used, revoked or expired', type: 'array{message: string, code: "invitation_used"|"invitation_revoked"|"invitation_expired", request_id: string}')]
    #[Response(status: 409, description: 'Already a member', type: 'array{message: string, code: "already_member", request_id: string}')]
    #[Response(status: 404, description: 'Unknown invitation token', type: 'array{message: string, code: "invitation_not_found", request_id: string}')]
    public function __invoke(AcceptInvitationRequest $request, InvitationService $invitations): JsonResponse
    {
        $user = $request->user();

        $membership = $invitations->accept($user, $request->validated('token'));

        return $this->authState($user, 'Invitation accepted.', meta: ['accepted_farm_id' => $membership->farm_id]);
    }
}
