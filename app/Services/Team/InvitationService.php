<?php

namespace App\Services\Team;

use App\Enums\FarmRole;
use App\Enums\Limit;
use App\Enums\MembershipStatus;
use App\Events\Access\InvitationAccepted;
use App\Events\Access\InvitationResent;
use App\Events\Access\InvitationRevoked;
use App\Events\Access\MemberInvited;
use App\Models\Farm;
use App\Models\FarmInvitation;
use App\Models\FarmMembership;
use App\Models\User;
use App\Notifications\FarmInvitationNotification;
use App\Services\Subscription\EntitlementService;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class InvitationService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** Guard shared by invite/role-change: Owner is never assignable; others are limited to assignableRoles(). */
    public static function assertCanGrant(FarmRole $actorRole, FarmRole $role): void
    {
        if ($role === FarmRole::Owner) {
            throw new ApiHttpException(422, 'ownership_transfer_unsupported', 'The Owner role cannot be assigned. Ownership transfer is not supported yet.');
        }

        if (! in_array($role, $actorRole->assignableRoles(), true)) {
            throw new ApiHttpException(403, 'insufficient_role', 'You cannot assign this role.');
        }
    }

    public function invite(FarmContext $ctx, User $actor, string $email, FarmRole $role): FarmInvitation
    {
        self::assertCanGrant($ctx->membership->role, $role);

        [$token, $invitation] = DB::transaction(function () use ($ctx, $actor, $email, $role) {
            // Serialise invitations per farm so two concurrent requests cannot both create a pending one.
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();

            // Plan capacity (entitlement) - separate from the RBAC check already passed. Evaluated under the
            // farm lock so concurrent invites cannot both take the last seat.
            $this->entitlements->assertCapacity($ctx->farm, Limit::TeamMembers);

            $isMember = FarmMembership::active()
                ->where('farm_id', $ctx->farm->id)
                ->whereIn('user_id', User::where('email', $email)->select('id'))
                ->exists();

            if ($isMember) {
                throw new ApiHttpException(409, 'already_member', 'This person is already a member of the farm.');
            }

            $pending = FarmInvitation::where('farm_id', $ctx->farm->id)
                ->where('email', $email)
                ->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                throw new ApiHttpException(409, 'invitation_already_pending', 'This email already has a pending invitation. Resend or revoke it instead.');
            }

            [$token, $hash] = FarmInvitation::newToken();

            $invitation = new FarmInvitation([
                'farm_id' => $ctx->farm->id,
                'email' => $email,
                'role' => $role->value,
                'invited_by_user_id' => $actor->id,
                'expires_at' => $this->expiry(),
            ]);
            $invitation->token_hash = $hash;
            $invitation->last_sent_at = now();
            $invitation->save();

            return [$token, $invitation];
        });

        $this->deliver($invitation, $ctx->farm, $actor, $token);
        MemberInvited::dispatch($invitation, $actor);

        return $invitation;
    }

    /** New token + fresh expiry; the previous link stops working. */
    public function resend(FarmContext $ctx, User $actor, FarmInvitation $invitation): FarmInvitation
    {
        $this->assertManageable($ctx, $invitation);

        [$token, $invitation] = DB::transaction(function () use ($ctx, $invitation) {
            Farm::whereKey($ctx->farm->id)->lockForUpdate()->firstOrFail();
            $locked = FarmInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);

            // Resending an EXPIRED invitation re-reserves a seat it no longer holds, so capacity applies again.
            if ($locked->expires_at->lte(now())) {
                $this->entitlements->assertCapacity($ctx->farm, Limit::TeamMembers);
            }

            [$token, $hash] = FarmInvitation::newToken();
            $locked->token_hash = $hash;
            $locked->expires_at = $this->expiry();
            $locked->last_sent_at = now();
            $locked->save();

            return [$token, $locked];
        });

        $this->deliver($invitation, $ctx->farm, $actor, $token);
        InvitationResent::dispatch($invitation, $actor);

        return $invitation;
    }

    public function revoke(FarmContext $ctx, User $actor, FarmInvitation $invitation): FarmInvitation
    {
        $this->assertManageable($ctx, $invitation);

        $invitation = DB::transaction(function () use ($invitation, $actor) {
            $locked = FarmInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);

            $locked->revoked_at = now();
            $locked->revoked_by_user_id = $actor->id;
            $locked->save();

            return $locked;
        });

        InvitationRevoked::dispatch($invitation, $actor);

        return $invitation;
    }

    /**
     * Accept an invitation as the authenticated (verified) user whose email matches it.
     * Single use; re-activates a previously removed membership instead of duplicating it.
     */
    public function accept(User $user, string $token): FarmMembership
    {
        [$membership, $invitation] = DB::transaction(function () use ($user, $token) {
            $invitation = FarmInvitation::where('token_hash', FarmInvitation::hashToken($token))->lockForUpdate()->first();

            if (! $invitation) {
                throw new ApiHttpException(404, 'invitation_not_found', 'This invitation link is not valid.');
            }

            match ($invitation->status()) {
                FarmInvitation::ACCEPTED => throw new ApiHttpException(410, 'invitation_used', 'This invitation has already been used.'),
                FarmInvitation::REVOKED => throw new ApiHttpException(410, 'invitation_revoked', 'This invitation was revoked.'),
                FarmInvitation::EXPIRED => throw new ApiHttpException(410, 'invitation_expired', 'This invitation has expired. Ask for a new one.'),
                default => null,
            };

            if (strtolower($user->email) !== strtolower($invitation->email)) {
                throw new ApiHttpException(403, 'invitation_email_mismatch', 'This invitation was sent to a different email address.');
            }

            $existing = FarmMembership::where('farm_id', $invitation->farm_id)->where('user_id', $user->id)->lockForUpdate()->first();

            if ($existing?->isActive()) {
                throw new ApiHttpException(409, 'already_member', 'You are already a member of this farm.');
            }

            if ($existing) {
                $existing->forceFill([
                    'role' => $invitation->role,
                    'status' => MembershipStatus::Active,
                    'removed_at' => null,
                    'removed_by_user_id' => null,
                ])->save();
                $membership = $existing;
            } else {
                $membership = FarmMembership::create([
                    'farm_id' => $invitation->farm_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role->value,
                ]);
            }

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by_user_id' => $user->id])->save();

            // Explicit rule: a verified user who never completed onboarding enters the product through this
            // farm instead of creating their own, so accepting counts as completing onboarding. This is the
            // only place membership sets onboarded_at; it is never cleared or derived from membership count.
            if (! $user->isOnboarded()) {
                $user->forceFill(['onboarded_at' => now()])->save();
            }

            return [$membership->load('farm'), $invitation];
        });

        InvitationAccepted::dispatch($invitation, $membership);

        return $membership;
    }

    private function expiry(): Carbon
    {
        return now()->addDays(config('identity.invitations.ttl_days'));
    }

    private function assertOpen(FarmInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw new ApiHttpException(409, 'invitation_not_pending', 'This invitation has already been accepted or revoked.');
        }
    }

    /** A member may only manage invitations for roles they could invite. */
    private function assertManageable(FarmContext $ctx, FarmInvitation $invitation): void
    {
        if (! in_array($invitation->role, $ctx->membership->role->assignableRoles(), true)) {
            throw new ApiHttpException(403, 'insufficient_role', 'You cannot manage this invitation.');
        }
    }

    private function deliver(FarmInvitation $invitation, Farm $farm, User $actor, string $token): void
    {
        try {
            Notification::route('mail', $invitation->email)->notify(new FarmInvitationNotification(
                $token,
                $farm->name,
                $actor->name ?? $actor->email,
                $invitation->role->label(),
                $invitation->expires_at,
            ));
        } catch (Throwable $e) {
            // The invitation exists and can be resent; never put the token in logs.
            report(new RuntimeException('Invitation email delivery failed: '.get_class($e)));
        }
    }
}
