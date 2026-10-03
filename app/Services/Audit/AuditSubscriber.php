<?php

namespace App\Services\Audit;

use App\Events\Access\FarmSettingsUpdated;
use App\Events\Access\InvitationAccepted;
use App\Events\Access\InvitationResent;
use App\Events\Access\InvitationRevoked;
use App\Events\Access\MemberInvited;
use App\Events\Access\MemberRemoved;
use App\Events\Access\MemberRoleChanged;
use Illuminate\Events\Dispatcher;

/**
 * Turns the security-relevant Access events (already dispatched after commit by the team and farm services) into audit entries. The
 * services stay unaware of auditing; an invitation's token is never part of an entry.
 */
class AuditSubscriber
{
    public function __construct(private AuditLogger $audit) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            MemberInvited::class => 'invited', InvitationResent::class => 'resent', InvitationRevoked::class => 'revoked', InvitationAccepted::class => 'accepted',
            MemberRoleChanged::class => 'roleChanged', MemberRemoved::class => 'removed', FarmSettingsUpdated::class => 'settings',
        ];
    }

    public function invited(MemberInvited $e): void
    {
        $this->audit->record($e->invitation->farm_id, $e->actor->id, 'team.invitation_sent', 'invitation', $e->invitation->id, $e->invitation->email, ['role' => $this->value($e->invitation->role)]);
    }

    public function resent(InvitationResent $e): void
    {
        $this->audit->record($e->invitation->farm_id, $e->actor->id, 'team.invitation_resent', 'invitation', $e->invitation->id, $e->invitation->email, ['role' => $this->value($e->invitation->role)]);
    }

    public function revoked(InvitationRevoked $e): void
    {
        $this->audit->record($e->invitation->farm_id, $e->actor->id, 'team.invitation_revoked', 'invitation', $e->invitation->id, $e->invitation->email);
    }

    public function accepted(InvitationAccepted $e): void
    {
        $this->audit->record($e->invitation->farm_id, $e->membership->user_id, 'team.invitation_accepted', 'membership', $e->membership->id, $e->invitation->email, ['role' => $this->value($e->membership->role)]);
    }

    public function roleChanged(MemberRoleChanged $e): void
    {
        $this->audit->record($e->membership->farm_id, $e->actor->id, 'team.member_role_changed', 'membership', $e->membership->id, $e->membership->user?->name, ['from' => $e->from->value, 'to' => $e->to->value]);
    }

    public function removed(MemberRemoved $e): void
    {
        $this->audit->record($e->membership->farm_id, $e->actor->id, 'team.member_removed', 'membership', $e->membership->id, $e->membership->user?->name, ['role' => $this->value($e->membership->role)]);
    }

    public function settings(FarmSettingsUpdated $e): void
    {
        $this->audit->record($e->farm->id, $e->actor->id, 'farm.settings_updated', 'farm', $e->farm->id, $e->farm->name, ['fields' => array_keys($e->changes), 'changes' => $e->changes]);
    }

    private function value(mixed $v): ?string
    {
        return $v instanceof \BackedEnum ? (string) $v->value : ($v === null ? null : (string) $v);
    }
}
