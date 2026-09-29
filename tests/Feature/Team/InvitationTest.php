<?php

namespace Tests\Feature\Team;

use App\Enums\FarmRole;
use App\Events\Access\InvitationAccepted;
use App\Events\Access\MemberInvited;
use App\Models\Farm;
use App\Models\FarmInvitation;
use App\Models\FarmMembership;
use App\Models\User;
use App\Notifications\FarmInvitationNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

class InvitationTest extends TeamTestCase
{
    private function invite(string $email = 'new@example.com', string $role = 'farm_worker')
    {
        return $this->postJson('/api/v1/farm/invitations', ['email' => $email, 'role' => $role]);
    }

    /** The plaintext token from the most recent invitation email to $email (the API never returns it). */
    private function lastToken(string $email): string
    {
        $token = null;

        Notification::assertSentOnDemand(FarmInvitationNotification::class, function (FarmInvitationNotification $n, $channels, $notifiable) use (&$token, $email) {
            if ($notifiable->routes['mail'] === $email) {
                $token = $n->token;
            }

            return true;
        });

        $this->assertNotNull($token, 'No invitation email was sent to '.$email);

        return $token;
    }

    public function test_owner_invites_by_email_and_token_is_only_in_the_email(): void
    {
        Event::fake([MemberInvited::class]);

        $response = $this->signInAs($this->owner)->postJson('/api/v1/farm/invitations', ['email' => '  New@Example.com ', 'role' => 'manager'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.invited_by.id', $this->owner->id);

        $this->assertStringNotContainsString('token', json_encode($response->json()));

        Notification::assertSentOnDemand(FarmInvitationNotification::class, function (FarmInvitationNotification $n, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'new@example.com'
                && $n->farmName === 'Green Acres'
                && str_contains($n->acceptUrl(), '/invitations/accept?token=');
        });
        Event::assertDispatched(MemberInvited::class);

        $invitation = FarmInvitation::firstOrFail();
        $this->assertSame($this->farm->id, $invitation->farm_id);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertTrue($invitation->expires_at->isFuture());
    }

    public function test_manager_can_invite_workers_and_finance_but_not_managers(): void
    {
        $this->signInAs($this->member(FarmRole::Manager));

        $this->invite('a@example.com', 'farm_worker')->assertCreated();
        $this->invite('b@example.com', 'finance')->assertCreated();
        $this->invite('c@example.com', 'manager')->assertForbidden()->assertJsonPath('code', 'insufficient_role');
        $this->invite('d@example.com', 'owner')->assertStatus(422)->assertJsonPath('code', 'ownership_transfer_unsupported');

        $this->assertSame(2, FarmInvitation::count());
    }

    public function test_worker_and_finance_cannot_invite_or_list(): void
    {
        foreach ([FarmRole::FarmWorker, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role));
            $this->invite()->assertForbidden()->assertJsonPath('code', 'forbidden');
            $this->getJson('/api/v1/farm/invitations')->assertForbidden();
        }

        $this->assertSame(0, FarmInvitation::count());
        Notification::assertNothingSent();
    }

    public function test_invalid_input_and_roles_are_rejected(): void
    {
        $this->signInAs($this->owner);

        $this->invite('not-an-email')->assertJsonValidationErrors('email');
        $this->invite('ok@example.com', 'admin')->assertJsonValidationErrors('role');
        $this->postJson('/api/v1/farm/invitations', [])->assertJsonValidationErrors(['email', 'role']);
        $this->invite('ok@example.com', 'owner')->assertStatus(422)->assertJsonPath('code', 'ownership_transfer_unsupported');
    }

    public function test_duplicate_pending_invitation_is_a_conflict_but_expired_or_revoked_can_be_reissued(): void
    {
        $this->signInAs($this->owner);

        $this->invite()->assertCreated();
        $this->invite('NEW@example.com')->assertStatus(409)->assertJsonPath('code', 'invitation_already_pending');
        $this->assertSame(1, FarmInvitation::count());

        FarmInvitation::firstOrFail()->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->invite()->assertCreated();

        $id = collect($this->getJson('/api/v1/farm/invitations')->json('data'))->firstWhere('status', 'pending')['id'];
        $this->deleteJson("/api/v1/farm/invitations/{$id}")->assertOk();
        $this->invite()->assertCreated();
    }

    public function test_inviting_an_existing_active_member_is_a_conflict_but_a_removed_one_is_allowed(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($this->owner);

        $this->invite($worker->email)->assertStatus(409)->assertJsonPath('code', 'already_member');
        $this->invite($this->owner->email, 'manager')->assertStatus(409)->assertJsonPath('code', 'already_member');

        $this->removeMembership($worker);
        $this->invite($worker->email)->assertCreated();
    }

    public function test_pending_invitations_are_listed_per_farm_without_tokens(): void
    {
        [$otherOwner] = $this->otherFarm();

        $this->signInAs($this->owner);
        $this->invite('mine@example.com')->assertCreated();
        $this->signInAs($otherOwner);
        $this->invite('theirs@example.com')->assertCreated();

        $rows = $this->signInAs($this->owner)->getJson('/api/v1/farm/invitations')->assertOk()->json('data');
        $this->assertSame(['mine@example.com'], array_column($rows, 'email'));
        $this->assertArrayNotHasKey('token', $rows[0]);
        $this->assertArrayNotHasKey('token_hash', $rows[0]);
    }

    public function test_cross_farm_invitation_cannot_be_revoked_or_resent(): void
    {
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $id = $this->invite('theirs@example.com')->json('data.id');

        $this->signInAs($this->owner);
        $this->deleteJson("/api/v1/farm/invitations/{$id}")->assertNotFound();
        $this->postJson("/api/v1/farm/invitations/{$id}/resend")->assertNotFound();
        $this->assertNull(FarmInvitation::findOrFail($id)->revoked_at);
    }

    public function test_manager_cannot_manage_an_invitation_for_a_manager_role(): void
    {
        $this->signInAs($this->owner);
        $id = $this->invite('boss@example.com', 'manager')->json('data.id');

        $this->signInAs($this->member(FarmRole::Manager))->deleteJson("/api/v1/farm/invitations/{$id}")->assertForbidden();
        $this->postJson("/api/v1/farm/invitations/{$id}/resend")->assertForbidden();
    }

    public function test_invited_new_user_accepts_and_gets_the_role_and_skips_farm_setup(): void
    {
        Event::fake([InvitationAccepted::class]);
        $this->signInAs($this->owner);
        $this->invite('newbie@example.com', 'finance')->assertCreated();
        $token = $this->lastToken('newbie@example.com');

        $newbie = User::factory()->create(['email' => 'newbie@example.com']);
        $this->assertNull($newbie->onboarded_at);
        $farms = Farm::count();
        $memberships = FarmMembership::count();

        $this->signInAs($newbie)->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.next_action', 'none')
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.farm.id', $this->farm->id)
            ->assertJsonPath('data.farm.role', 'finance')
            ->assertJsonPath('meta.accepted_farm_id', $this->farm->id);

        $membership = $this->membershipOf($newbie);
        $this->assertSame(FarmRole::Finance, $membership->role);
        $this->assertNotNull($newbie->fresh()->onboarded_at);
        $this->assertSame($farms, Farm::count(), 'accepting must not create a farm');
        $this->assertSame($memberships + 1, FarmMembership::count(), 'exactly one membership is added');
        $this->assertNotNull(FarmInvitation::firstOrFail()->accepted_at);
        $this->assertSame($newbie->id, FarmInvitation::firstOrFail()->accepted_by_user_id);
        Event::assertDispatched(InvitationAccepted::class);

        $this->getJson('/api/v1/farm')->assertOk()->assertJsonPath('data.membership.role', 'finance');
    }

    public function test_invitation_is_single_use(): void
    {
        $this->signInAs($this->owner);
        $this->invite('once@example.com')->assertCreated();
        $token = $this->lastToken('once@example.com');

        $first = User::factory()->create(['email' => 'once@example.com']);
        $this->signInAs($first)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        $this->signInAs($first)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(410)->assertJsonPath('code', 'invitation_used');
        $this->assertSame(1, FarmMembership::where('user_id', $first->id)->count());
    }

    public function test_accepting_does_not_duplicate_an_existing_membership(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($this->owner);
        $this->invite('temp@example.com')->assertCreated();
        // The invitee later became a member through another path (e.g. same email invited earlier, then user changed)
        FarmInvitation::firstOrFail()->forceFill(['email' => $worker->email])->save();
        $token = $this->lastToken('temp@example.com');

        $this->signInAs($worker)->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertStatus(409)->assertJsonPath('code', 'already_member');

        $this->assertSame(1, FarmMembership::where('user_id', $worker->id)->count());
        $this->assertSame(FarmRole::FarmWorker, $this->membershipOf($worker)->role);
        $this->assertNull(FarmInvitation::firstOrFail()->accepted_at);
    }

    public function test_removed_member_is_reactivated_not_duplicated(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->removeMembership($worker);
        $onboardedAt = $worker->fresh()->onboarded_at->toDateTimeString();

        $this->signInAs($this->owner);
        $this->invite($worker->email, 'manager')->assertCreated();
        $token = $this->lastToken($worker->email);

        $this->signInAs($worker)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();
        $this->assertSame($onboardedAt, $worker->fresh()->onboarded_at->toDateTimeString());

        $this->assertSame(1, FarmMembership::where('user_id', $worker->id)->count());
        $membership = $this->membershipOf($worker);
        $this->assertTrue($membership->isActive());
        $this->assertSame(FarmRole::Manager, $membership->role);
        $this->assertNull($membership->removed_at);
    }

    public function test_existing_farm_owner_can_join_a_second_farm_and_keeps_their_own(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->signInAs($this->owner);
        $this->invite($otherOwner->email, 'manager')->assertCreated();
        $token = $this->lastToken($otherOwner->email);

        $this->signInAs($otherOwner)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk()
            ->assertJsonPath('data.farm.id', $otherFarm->id); // default farm unchanged (oldest membership)

        $this->getJson('/api/v1/farm')->assertJsonPath('data.id', $otherFarm->id)->assertJsonPath('data.membership.role', 'owner');
        $this->withHeader('X-Farm-Id', $this->farm->id)->getJson('/api/v1/farm')
            ->assertJsonPath('data.id', $this->farm->id)->assertJsonPath('data.membership.role', 'manager');
    }

    public function test_wrong_account_cannot_accept(): void
    {
        $this->signInAs($this->owner);
        $this->invite('intended@example.com')->assertCreated();
        $token = $this->lastToken('intended@example.com');

        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $this->signInAs($stranger)->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertForbidden()->assertJsonPath('code', 'invitation_email_mismatch');

        $this->assertSame(0, FarmMembership::where('user_id', $stranger->id)->count());
        $this->assertNull($stranger->fresh()->onboarded_at);
    }

    public function test_unverified_account_cannot_accept_even_with_the_right_email(): void
    {
        $this->signInAs($this->owner);
        $this->invite('unverified@example.com')->assertCreated();
        $token = $this->lastToken('unverified@example.com');

        $user = User::factory()->unverified()->create(['email' => 'unverified@example.com']);
        $this->signInAs($user)->postJson('/api/v1/invitations/accept', ['token' => $token])
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $this->assertSame(0, FarmMembership::where('user_id', $user->id)->count());
    }

    public function test_unknown_token_and_guest_are_rejected(): void
    {
        $this->postJson('/api/v1/invitations/accept', ['token' => 'x'])->assertUnauthorized();

        $this->signInAs(User::factory()->create())->postJson('/api/v1/invitations/accept', ['token' => 'nope'])
            ->assertNotFound()->assertJsonPath('code', 'invitation_not_found');
        $this->postJson('/api/v1/invitations/accept', [])->assertJsonValidationErrors('token');
    }

    public function test_expired_invitation_is_rejected(): void
    {
        $this->signInAs($this->owner);
        $this->invite('late@example.com')->assertCreated();
        $token = $this->lastToken('late@example.com');
        FarmInvitation::firstOrFail()->forceFill(['expires_at' => now()->subSecond()])->save();

        $late = User::factory()->create(['email' => 'late@example.com']);
        $this->signInAs($late)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(410)->assertJsonPath('code', 'invitation_expired');

        $this->assertSame(0, FarmMembership::where('user_id', $late->id)->count());
        $this->signInAs($this->owner)->getJson('/api/v1/farm/invitations')->assertJsonPath('data.0.status', 'expired');
    }

    public function test_revoked_invitation_is_rejected(): void
    {
        $this->signInAs($this->owner);
        $id = $this->invite('gone@example.com')->json('data.id');
        $token = $this->lastToken('gone@example.com');

        $this->deleteJson("/api/v1/farm/invitations/{$id}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->deleteJson("/api/v1/farm/invitations/{$id}")->assertStatus(409)->assertJsonPath('code', 'invitation_not_pending');

        $user = User::factory()->create(['email' => 'gone@example.com']);
        $this->signInAs($user)->postJson('/api/v1/invitations/accept', ['token' => $token])->assertStatus(410)->assertJsonPath('code', 'invitation_revoked');
    }

    public function test_resend_replaces_the_link_and_extends_expiry(): void
    {
        $this->signInAs($this->owner);
        $id = $this->invite('again@example.com')->json('data.id');
        $oldToken = $this->lastToken('again@example.com');
        FarmInvitation::firstOrFail()->forceFill(['expires_at' => now()->subDay()])->save();

        $this->postJson("/api/v1/farm/invitations/{$id}/resend")->assertOk()->assertJsonPath('data.status', 'pending');
        $newToken = $this->lastToken('again@example.com');
        $this->assertNotSame($oldToken, $newToken);

        $user = User::factory()->create(['email' => 'again@example.com']);
        $this->signInAs($user)->postJson('/api/v1/invitations/accept', ['token' => $oldToken])->assertNotFound();
        $this->postJson('/api/v1/invitations/accept', ['token' => $newToken])->assertOk();

        $this->signInAs($this->owner)->postJson("/api/v1/farm/invitations/{$id}/resend")->assertStatus(409);
    }

    public function test_email_failure_does_not_lose_the_invitation(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('smtp down'));

        $this->signInAs($this->owner)->invite('flaky@example.com')->assertCreated();

        $this->assertSame(1, FarmInvitation::where('email', 'flaky@example.com')->count());
    }
}
