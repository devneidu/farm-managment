<?php

namespace App\Services\Team;

use App\Enums\FarmRole;
use App\Enums\MembershipStatus;
use App\Events\Access\MemberRemoved;
use App\Events\Access\MemberRoleChanged;
use App\Models\FarmMembership;
use App\Models\User;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\DB;

/**
 * Role changes and removals. Privilege-escalation and owner protections live here so no
 * controller has to compare roles.
 */
class TeamService
{
    public function changeRole(FarmContext $ctx, User $actor, FarmMembership $target, FarmRole $newRole): FarmMembership
    {
        $actorRole = $ctx->membership->role;

        InvitationService::assertCanGrant($actorRole, $newRole);

        [$target, $from] = DB::transaction(function () use ($ctx, $target, $newRole, $actorRole) {
            $target = $this->lockActiveTarget($ctx, $target);

            $this->assertActorMayManage($actorRole, $target);
            $this->assertOwnerRemainsIfNeeded($ctx, $target);

            if ($target->id === $ctx->membership->id) {
                throw new ApiHttpException(403, 'cannot_change_own_role', 'You cannot change your own role.');
            }

            $from = $target->role;
            $target->forceFill(['role' => $newRole])->save();

            return [$target, $from];
        });

        if ($from !== $newRole) {
            MemberRoleChanged::dispatch($target, $from, $newRole, $actor);
        }

        return $target;
    }

    public function remove(FarmContext $ctx, User $actor, FarmMembership $target): FarmMembership
    {
        $target = DB::transaction(function () use ($ctx, $actor, $target) {
            $target = $this->lockActiveTarget($ctx, $target);

            $this->assertActorMayManage($ctx->membership->role, $target);
            $this->assertOwnerRemainsIfNeeded($ctx, $target);

            if ($target->id === $ctx->membership->id) {
                throw new ApiHttpException(403, 'cannot_remove_self', 'You cannot remove yourself from the farm.');
            }

            $target->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
                'removed_by_user_id' => $actor->id,
            ])->save();

            // onboarded_at is deliberately NOT touched: it records that the user completed onboarding,
            // not that they currently belong to a farm (they may have no active farm; see AuthStateResource).
            return $target;
        });

        MemberRemoved::dispatch($target, $actor);

        return $target;
    }

    /** Re-reads the target under a lock, scoped to the acting farm and active only (cross-farm ids are 404). */
    private function lockActiveTarget(FarmContext $ctx, FarmMembership $target): FarmMembership
    {
        return FarmMembership::active()
            ->where('farm_id', $ctx->farm->id)
            ->whereKey($target->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertActorMayManage(FarmRole $actorRole, FarmMembership $target): void
    {
        if (! $actorRole->canManage($target->role)) {
            throw new ApiHttpException(403, 'insufficient_role', 'You cannot manage a member with this role.');
        }
    }

    /** A farm must never become ownerless: block demoting/removing the last active owner. */
    private function assertOwnerRemainsIfNeeded(FarmContext $ctx, FarmMembership $target): void
    {
        if ($target->role !== FarmRole::Owner) {
            return;
        }

        $owners = FarmMembership::active()
            ->where('farm_id', $ctx->farm->id)
            ->where('role', FarmRole::Owner->value)
            ->lockForUpdate()
            ->count();

        if ($owners <= 1) {
            throw new ApiHttpException(409, 'last_owner', 'A farm must keep at least one owner. Ownership transfer is not supported yet.');
        }
    }
}
