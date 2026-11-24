<?php

namespace Tests\Feature\Account;

use App\Enums\DealStatus;
use App\Enums\FarmRole;
use App\Enums\ListingStatus;
use App\Enums\MembershipStatus;
use App\Enums\OfferStatus;
use App\Enums\OtpPurpose;
use App\Enums\PlatformRole;
use App\Enums\ShopStatus;
use App\Models\AuditLog;
use App\Models\Farm;
use App\Models\FarmInvitation;
use App\Models\FarmMembership;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\OneTimeCode;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\AccountDeletedNotification;
use App\Notifications\OtpNotification;
use App\Services\Account\AccountDeletionService;
use App\Services\Auth\OtpService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Marketplace\DealTestCase;

/**
 * Account deletion by anonymisation, ownership transfer and the platform-admin variant. Built on the marketplace fixtures so the buyer/seller
 * sides (shops, listings, offers, deals) are available next to the farm team ($this->owner / $this->farm from the team fixtures).
 */
class AccountDeletionTest extends DealTestCase
{
    private const DELETE = '/api/v1/account/delete';

    private const ADMIN_API = '/api/v1/platform-admin';

    private function body(array $extra = []): array
    {
        return array_replace(['confirm' => 'DELETE', 'password' => 'Password123'], $extra);
    }

    /** A user who owns a farm nobody else belongs to. */
    private function soloOwner(string $name = 'Solo Owner'): User
    {
        return User::factory()->onboarded('Solo Farm')->create(['name' => $name]);
    }

    /**
     * A Google-style account with no password. The in-process test session survives between users (a real browser has its own), and Sanctum's
     * session guard compares the session's stored password hash with the user's, so start from an empty session.
     */
    private function passwordless(?string $name = null, ?string $farm = null): User
    {
        $this->flushSession();

        return ($farm ? User::factory()->onboarded($farm) : User::factory())->create(['name' => $name, 'password' => null]);
    }

    private function deleteAs(User $user, array $extra = [])
    {
        return $this->signInAs($user)->postJson(self::DELETE, $this->body($extra));
    }

    /** A Bearer token for a native app: no Origin header, no cookie. */
    private function asToken(User $user): string
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $user->createToken('Pixel 8')->plainTextToken;
    }

    private function withBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function inviteRow(Farm $farm, User $by, string $email): FarmInvitation
    {
        [, $hash] = FarmInvitation::newToken();
        $invitation = new FarmInvitation(['farm_id' => $farm->id, 'email' => $email, 'role' => FarmRole::FarmWorker->value, 'invited_by_user_id' => $by->id, 'expires_at' => now()->addDays(7)]);
        $invitation->forceFill(['token_hash' => $hash])->save();

        return $invitation;
    }

    // ------------------------------------------------------------------ confirmation

    public function test_a_wrong_password_a_wrong_confirmation_and_a_missing_credential_delete_nothing(): void
    {
        $user = $this->soloOwner();

        $this->deleteAs($user, ['password' => 'WrongPassword1'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->deleteAs($user, ['confirm' => 'delete'])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->deleteAs($user, ['confirm' => null])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->signInAs($user)->postJson(self::DELETE, ['confirm' => 'DELETE'])->assertStatus(422);

        $this->assertFalse($user->fresh()->isAnonymised());
        $this->assertNull($user->currentFarm()->archived_at);
        Notification::assertNothingSent();
    }

    public function test_a_password_account_deletes_with_its_password_and_the_old_email_gets_one_final_message(): void
    {
        $user = $this->soloOwner();
        $oldEmail = $user->email;

        $this->deleteAs($user)->assertOk()->assertJsonPath('message', 'Your account has been deleted.');

        Notification::assertSentOnDemand(AccountDeletedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === $oldEmail);
        Notification::assertSentOnDemandTimes(AccountDeletedNotification::class, 1);
    }

    public function test_a_google_only_account_deletes_with_an_emailed_code(): void
    {
        $user = $this->passwordless('Gina Google');
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-123', 'email' => $user->email]);

        $this->signInAs($user)->postJson(self::DELETE.'/request-code')->assertOk()->assertJsonPath('data.cooldown_seconds', 60);
        $code = $this->lastCode($user, OtpPurpose::AccountDeletion);
        $this->assertNotNull($code);

        // a wrong code and a password (the account has none) fail without spending the real code
        $this->postJson(self::DELETE, ['confirm' => 'DELETE', 'code' => $this->wrongCode($code)])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson(self::DELETE, ['confirm' => 'DELETE', 'password' => 'Password123'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertFalse($user->fresh()->isAnonymised());

        $this->postJson(self::DELETE, ['confirm' => 'DELETE', 'code' => $code])->assertOk();

        $this->assertTrue($user->fresh()->isAnonymised());
        $this->assertSame(0, SocialAccount::where('user_id', $user->id)->count());
    }

    public function test_a_code_is_single_use_and_a_new_request_waits_for_the_cooldown(): void
    {
        $user = $this->passwordless();

        $this->signInAs($user)->postJson(self::DELETE.'/request-code')->assertOk();
        $this->postJson(self::DELETE.'/request-code')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

        $code = $this->lastCode($user, OtpPurpose::AccountDeletion);
        $this->assertTrue(app(OtpService::class)->consume($user, OtpPurpose::AccountDeletion, $code));
        $this->postJson(self::DELETE, ['confirm' => 'DELETE', 'code' => $code])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_blocked_request_does_not_spend_the_code(): void
    {
        $user = $this->passwordless('Team Owner', 'Team Farm');
        FarmMembership::create(['farm_id' => $user->currentFarm()->id, 'user_id' => User::factory()->create()->id, 'role' => FarmRole::Manager->value]);

        $this->signInAs($user)->postJson(self::DELETE.'/request-code')->assertOk();
        $code = $this->lastCode($user, OtpPurpose::AccountDeletion);

        $this->postJson(self::DELETE, ['confirm' => 'DELETE', 'code' => $code])->assertStatus(409)->assertJsonPath('code', 'account_deletion_blocked');
        $this->assertTrue(app(OtpService::class)->consume($user, OtpPurpose::AccountDeletion, $code));
    }

    public function test_an_unverified_never_onboarded_account_can_delete_itself(): void
    {
        $user = User::factory()->unverified()->create();

        $this->signInAs($user)->getJson(self::DELETE.'/check')->assertOk()->assertJsonPath('data.allowed', true);
        $this->deleteAs($user)->assertOk();
        $this->assertTrue($user->fresh()->isAnonymised());
    }

    public function test_guests_cannot_use_the_endpoints(): void
    {
        $this->app['auth']->forgetGuards();   // the fixtures leave the seller signed in
        $this->getJson(self::DELETE.'/check')->assertUnauthorized();
        $this->postJson(self::DELETE.'/request-code')->assertUnauthorized();
        $this->postJson(self::DELETE, $this->body())->assertUnauthorized();
    }

    public function test_the_code_email_uses_its_own_wording(): void
    {
        $user = User::factory()->create();

        $this->signInAs($user)->postJson(self::DELETE.'/request-code')->assertOk();

        Notification::assertSentTo($user, OtpNotification::class, fn (OtpNotification $n) => $n->purpose === OtpPurpose::AccountDeletion && str_contains($n->toMail($user)->subject, 'deleting'));
    }

    // ------------------------------------------------------------------ blockers and the check endpoint

    public function test_owning_a_farm_with_other_members_blocks_and_names_the_farm(): void
    {
        $this->member(FarmRole::Manager);

        $check = $this->signInAs($this->owner)->getJson(self::DELETE.'/check')->assertOk()
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.blockers.0.code', 'owner_must_transfer')
            ->assertJsonPath('data.blockers.0.farm.id', $this->farm->id)
            ->assertJsonPath('data.blockers.0.farm.name', 'Green Acres');
        $this->assertStringContainsString('Green Acres', $check->json('data.blockers.0.message'));

        $this->postJson(self::DELETE, $this->body())->assertStatus(409)
            ->assertJsonPath('code', 'account_deletion_blocked')
            ->assertJsonPath('details.blockers.0.code', 'owner_must_transfer');

        $fresh = $this->owner->fresh();
        $this->assertFalse($fresh->isAnonymised());
        $this->assertSame('Olu Owner', $fresh->name);
        $this->assertTrue($this->membershipOf($this->owner)->isActive());
    }

    public function test_a_farm_with_only_removed_people_does_not_block(): void
    {
        $gone = $this->member(FarmRole::FarmWorker);
        $this->removeMembership($gone);

        $this->signInAs($this->owner)->getJson(self::DELETE.'/check')->assertOk()->assertJsonPath('data.allowed', true)->assertJsonPath('data.blockers', []);
    }

    public function test_open_marketplace_deals_block_both_the_buyer_and_the_shop_owner(): void
    {
        $dealId = $this->dealId();
        $this->assertSame(DealStatus::Accepted, $this->deal($dealId)->status);

        $this->signInAs($this->buyer)->getJson(self::DELETE.'/check')->assertOk()
            ->assertJsonPath('data.allowed', false)->assertJsonPath('data.blockers.0.code', 'marketplace_deals_open')->assertJsonPath('data.blockers.0.count', 1);
        $this->postJson(self::DELETE, $this->body())->assertStatus(409)->assertJsonPath('details.blockers.0.code', 'marketplace_deals_open');

        $this->signInAs($this->sellerUser)->getJson(self::DELETE.'/check')->assertOk()
            ->assertJsonPath('data.blockers.0.code', 'marketplace_deals_open')->assertJsonPath('data.blockers.0.shops.0.id', $this->shopId);
        $this->postJson(self::DELETE, $this->body())->assertStatus(409);
        $this->assertSame(ShopStatus::Active, MarketplaceShop::find($this->shopId)->status);

        // once the deal is cancelled the same request succeeds
        $this->dealAction('cancel', $dealId, ['reason' => 'changed_mind'], $this->buyer)->assertOk();
        $this->deleteAs($this->buyer)->assertOk();
    }

    public function test_a_platform_administrator_must_have_the_grant_revoked_first(): void
    {
        $admin = $this->admin();

        $this->signInAs($admin)->getJson(self::DELETE.'/check')->assertOk()->assertJsonPath('data.blockers.0.code', 'platform_admin_protected');
        $this->postJson(self::DELETE, $this->body())->assertStatus(409);
    }

    // ------------------------------------------------------------------ ownership transfer

    public function test_the_owner_transfers_ownership_and_can_then_delete_the_account(): void
    {
        $manager = $this->member(FarmRole::Manager, name: 'Mary Manager');
        $managerMembership = $this->membershipOf($manager);

        $this->signInAs($this->owner)->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $managerMembership->id])->assertOk()
            ->assertJsonPath('data.new_owner.role', 'owner')->assertJsonPath('data.new_owner.user.name', 'Mary Manager')
            ->assertJsonPath('data.previous_owner.role', 'manager');

        $this->assertSame(FarmRole::Owner, $this->membershipOf($manager)->role);
        $this->assertSame(FarmRole::Manager, $this->membershipOf($this->owner)->role);
        $this->assertSame(1, FarmMembership::active()->where('farm_id', $this->farm->id)->where('role', 'owner')->count());
        $this->assertSame(2, AuditLog::where('farm_id', $this->farm->id)->where('action', 'team.member_role_changed')->count());

        // the former owner is now a manager: they may not transfer again, and are free to leave
        $this->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $managerMembership->id])->assertForbidden();
        $this->getJson(self::DELETE.'/check')->assertOk()->assertJsonPath('data.allowed', true);
        $this->deleteAs($this->owner)->assertOk();

        $this->assertNull($this->farm->fresh()->archived_at);   // the farm lives on under its new owner
        $this->assertTrue($this->membershipOf($manager)->isActive());
        $this->assertSame(MembershipStatus::Removed, $this->membershipOf($this->owner)->status);
    }

    public function test_only_the_owner_can_transfer_and_only_to_an_active_member_of_the_same_farm(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $worker = $this->member(FarmRole::FarmWorker);
        $removed = $this->member(FarmRole::Finance);
        $this->removeMembership($removed);
        [$otherOwner, $otherFarm] = $this->otherFarm();

        $this->signInAs($manager)->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $this->membershipOf($worker)->id])->assertForbidden();
        $this->signInAs($worker)->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $this->membershipOf($manager)->id])->assertForbidden();

        $this->signInAs($this->owner);
        $this->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $this->membershipOf($removed)->id])->assertNotFound();
        $this->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $this->membershipOf($otherOwner, $otherFarm)->id])->assertNotFound();
        $this->postJson('/api/v1/farm/transfer-ownership', ['member_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/api/v1/farm/transfer-ownership', ['member_id' => $this->membershipOf($this->owner)->id])->assertStatus(422)->assertJsonPath('code', 'cannot_transfer_to_self');
        $this->postJson('/api/v1/farm/transfer-ownership', [])->assertJsonValidationErrors('member_id');

        $this->assertSame(FarmRole::Owner, $this->membershipOf($this->owner)->role);
        $this->assertSame(FarmRole::Owner, $this->membershipOf($otherOwner, $otherFarm)->role);
    }

    // ------------------------------------------------------------------ what deletion does

    public function test_deleting_anonymises_the_account_and_frees_the_email(): void
    {
        $user = $this->soloOwner('Sola Sole');
        $oldEmail = $user->email;
        $user->forceFill(['locale' => 'yo'])->save();
        OneTimeCode::create(['user_id' => $user->id, 'purpose' => OtpPurpose::EmailVerification->value, 'secret_hash' => 'x', 'expires_at' => now()->addHour()]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1', 'email' => $oldEmail]);
        FarmMembership::where('user_id', $user->id)->update(['notification_preferences' => json_encode(['email' => true])]);

        $this->deleteAs($user)->assertOk();

        $fresh = User::find($user->id);
        $this->assertSame('Deleted user', $fresh->name);
        $this->assertSame('deleted+'.$user->id.'@deleted.invalid', $fresh->email);
        $this->assertNull($fresh->password);
        $this->assertNull($fresh->email_verified_at);
        $this->assertNull($fresh->locale);
        $this->assertNull($fresh->remember_token);
        $this->assertNotNull($fresh->anonymised_at);
        $this->assertSame(0, OneTimeCode::where('user_id', $user->id)->count());
        $this->assertSame(0, SocialAccount::where('user_id', $user->id)->count());
        $this->assertSame(0, FarmMembership::where('user_id', $user->id)->whereNotNull('notification_preferences')->count());
        $this->assertSame(0, User::where('email', $oldEmail)->count());
        $this->assertSame(1, User::where('id', $user->id)->count());   // the row is kept for history
    }

    public function test_a_sole_member_farm_is_archived_its_data_is_kept_and_its_paid_plan_stops_renewing(): void
    {
        $user = $this->soloOwner();
        $farm = $user->currentFarm();
        $paid = Plan::where('is_default', false)->where('is_active', true)->orderBy('sort_order')->firstOrFail();
        $this->onPlan($paid->slug, $farm);
        $invitation = $this->inviteRow($farm, $user, 'someone@example.com');

        $this->deleteAs($user)->assertOk();

        $this->assertNotNull($farm->fresh()->archived_at);
        $this->assertSame(0, FarmMembership::active()->where('farm_id', $farm->id)->count());
        $this->assertTrue($farm->subscription()->first()->cancel_at_period_end);
        $this->assertSame(FarmInvitation::REVOKED, $invitation->fresh()->status());
        $this->assertSame(1, Farm::whereKey($farm->id)->count());   // nothing is deleted

        // nobody can open it: a stale farm id is refused
        $stranger = User::factory()->onboarded('Elsewhere')->create();
        $this->signInAs($stranger)->withHeader('X-Farm-Id', $farm->id)->getJson('/api/v1/farm')->assertForbidden();
    }

    public function test_a_member_leaving_a_shared_farm_ends_only_their_own_membership(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, name: 'Wale Worker');

        $this->deleteAs($worker)->assertOk();

        $this->assertSame(MembershipStatus::Removed, $this->membershipOf($worker)->status);
        $this->assertNull($this->farm->fresh()->archived_at);
        $this->assertTrue($this->membershipOf($this->owner)->isActive());
    }

    public function test_other_members_still_read_the_history_and_see_deleted_user_instead_of_the_name(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, name: 'Wale Worker');

        $this->deleteAs($worker)->assertOk();

        $audit = $this->signInAs($this->owner)->getJson('/api/v1/audit?per_page=100')->assertOk()->json('data');
        $left = collect($audit)->firstWhere('action', 'account.member_left');
        $this->assertNotNull($left);
        $this->assertSame('Deleted user', $left['actor']['name']);
        $this->assertStringNotContainsString('Wale', json_encode($audit));

        $names = collect($this->getJson('/api/v1/farm/members')->assertOk()->json('data'))->pluck('user.name');
        $this->assertSame(['Olu Owner'], $names->all());
    }

    public function test_audit_labels_that_copied_a_name_are_replaced_and_the_entry_survives(): void
    {
        $manager = $this->member(FarmRole::Manager, name: 'Mary Manager');
        $this->signInAs($this->owner)->patchJson('/api/v1/farm/members/'.$this->membershipOf($manager)->id, ['role' => 'finance'])->assertOk();
        $this->assertSame(1, AuditLog::where('resource_label', 'Mary Manager')->count());

        $this->deleteAs($manager)->assertOk();

        $this->assertSame(0, AuditLog::where('resource_label', 'Mary Manager')->count());
        $entry = AuditLog::where('action', 'team.member_role_changed')->firstOrFail();
        $this->assertSame('Deleted user', $entry->resource_label);
        $this->assertEquals(['from' => 'manager', 'to' => 'finance'], $entry->changes);
    }

    public function test_the_deletion_itself_is_audited_without_personal_data(): void
    {
        $user = $this->soloOwner('Sola Sole');
        $email = $user->email;

        $this->deleteAs($user)->assertOk();

        $entry = AuditLog::where('action', 'account.deleted')->firstOrFail();
        $this->assertSame($user->id, $entry->actor_id);
        $this->assertSame($user->id, $entry->resource_id);
        $this->assertNull($entry->resource_label);
        $this->assertNull($entry->farm_id);
        $this->assertEquals(['method' => 'password', 'farms_archived' => 1, 'memberships_ended' => 1, 'shops_closed' => 0], $entry->changes);
        $this->assertStringNotContainsString($email, json_encode($entry->toArray()));
        $this->assertStringNotContainsString('Sola', json_encode($entry->toArray()));
    }

    public function test_invitations_they_sent_are_cancelled_and_those_addressed_to_their_old_email_expire(): void
    {
        $manager = $this->member(FarmRole::Manager);
        $sent = $this->inviteRow($this->farm, $manager, 'newhire@example.com');
        $received = $this->inviteRow($this->farm, $this->owner, $manager->email);
        $unrelated = $this->inviteRow($this->farm, $this->owner, 'keep@example.com');

        $this->deleteAs($manager)->assertOk();

        $this->assertSame(FarmInvitation::REVOKED, $sent->fresh()->status());
        $this->assertSame(FarmInvitation::EXPIRED, $received->fresh()->status());
        $this->assertStringEndsWith('@deleted.invalid', $received->fresh()->email);
        $this->assertSame(FarmInvitation::PENDING, $unrelated->fresh()->status());
        $this->assertSame('newhire@example.com', $sent->fresh()->email);   // the invitee's address is not the deleted user's data
    }

    // ------------------------------------------------------------------ sign-in afterwards

    public function test_tokens_are_revoked_and_the_account_can_never_sign_in_again(): void
    {
        $user = $this->soloOwner();
        $email = $user->email;
        $token = $this->asToken($user);
        $other = $user->createToken('Tablet')->plainTextToken;

        $this->withBearer($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withBearer($token)->postJson(self::DELETE, $this->body())->assertOk();

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $user->id)->count());
        $this->withBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withBearer($other)->getJson('/api/v1/account')->assertUnauthorized();

        // neither the old nor the placeholder email, on the web or the mobile route
        $this->app['auth']->forgetGuards();
        $this->withoutToken();
        foreach ([$email, 'deleted+'.$user->id.'@deleted.invalid'] as $address) {
            $this->postJson('/api/v1/auth/mobile/login', ['email' => $address, 'password' => 'Password123', 'device_name' => 'Pixel'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
            $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/login', ['email' => $address, 'password' => 'Password123'])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        }
    }

    public function test_a_session_that_outlives_the_deletion_is_treated_as_signed_out(): void
    {
        $user = $this->soloOwner();
        $this->signInAs($user)->getJson('/api/v1/auth/me')->assertOk();

        app(AccountDeletionService::class)->delete($user);   // ended elsewhere, e.g. by support

        $this->signInAs($user->fresh())->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_registering_again_with_the_same_email_creates_a_separate_account(): void
    {
        $user = $this->soloOwner();
        $email = $user->email;
        $this->deleteAs($user)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => $email]))->assertCreated();

        $new = User::where('email', $email)->firstOrFail();
        $this->assertNotSame($user->id, $new->id);
        $this->assertFalse($new->isAnonymised());
        $this->assertSame(0, $new->memberships()->count());   // none of the old account's farms or history follow the new one
        $this->assertSame('Deleted user', $user->fresh()->name);
    }

    // ------------------------------------------------------------------ marketplace

    public function test_the_shop_owners_shop_is_closed_and_listings_withdrawn(): void
    {
        $offerId = $this->offerId();   // a buyer's pending offer on the shop's listing
        $this->assertSame(OfferStatus::Pending, $this->stored($offerId)->status);

        $this->deleteAs($this->sellerUser)->assertOk();

        $shop = MarketplaceShop::find($this->shopId);
        $this->assertSame(ShopStatus::Closed, $shop->status);
        $this->assertNotNull($shop->closed_at);
        $this->assertNull($shop->contact_phone);
        $this->assertNull($shop->contact_email);
        $this->assertNull($shop->address_line);
        $this->assertSame(0, MarketplaceListing::where('shop_id', $this->shopId)->where('status', ListingStatus::Published->value)->count());
        $this->assertSame(ListingStatus::Archived, MarketplaceListing::find($this->listingId)->status);
        $this->assertSame(OfferStatus::Voided, $this->stored($offerId)->status);
        $this->assertSame([], $this->publicSlugs());
        $this->assertSame(0, MarketplaceShopMember::where('user_id', $this->sellerUser->id)->count());
    }

    public function test_a_buyers_pending_offers_are_withdrawn(): void
    {
        $pending = $this->offerId(['unit_price' => '7100']);

        $this->deleteAs($this->buyer)->assertOk();

        $this->assertSame(OfferStatus::Voided, $this->stored($pending)->status);
        $this->assertSame(ShopStatus::Active, MarketplaceShop::find($this->shopId)->status);   // someone else's shop is untouched
    }

    public function test_a_buyers_phone_is_removed_from_their_finished_deals(): void
    {
        $dealId = $this->dealId(['contact_phone' => self::BUYER_PHONE], $this->buyer);
        $this->dealAction('cancel', $dealId, ['reason' => 'changed_mind'], $this->buyer)->assertOk();
        $this->assertSame(self::BUYER_PHONE, $this->deal($dealId)->buyer_contact_phone);

        $this->deleteAs($this->buyer)->assertOk();

        $deal = $this->deal($dealId);
        $this->assertNull($deal->buyer_contact_phone);
        $this->assertSame(DealStatus::Cancelled, $deal->status);   // the deal record itself stays
    }

    public function test_a_shop_staff_member_just_gives_up_their_seat(): void
    {
        $staff = $this->addMember($this->sellerUser, $this->shopId, 'staff', 'Sade Staff');

        $this->deleteAs($staff)->assertOk();

        $this->assertSame(ShopStatus::Active, MarketplaceShop::find($this->shopId)->status);
        $this->assertSame(0, MarketplaceShopMember::where('user_id', $staff->id)->count());
        $this->assertSame(ListingStatus::Published, MarketplaceListing::find($this->listingId)->status);
    }

    // ------------------------------------------------------------------ idempotency and both clients

    public function test_deleting_twice_changes_nothing_the_second_time(): void
    {
        $user = $this->soloOwner();
        $service = app(AccountDeletionService::class);

        $this->assertFalse($service->delete($user)['already_deleted']);
        $afterFirst = User::find($user->id)->only(['name', 'email', 'anonymised_at']);

        $this->assertTrue($service->delete($user->fresh())['already_deleted']);

        $this->assertEquals($afterFirst, User::find($user->id)->only(['name', 'email', 'anonymised_at']));
        $this->assertSame(1, AuditLog::where('action', 'account.deleted')->count());
        Notification::assertSentOnDemandTimes(AccountDeletedNotification::class, 1);
    }

    public function test_repeating_the_request_after_success_is_unauthorised(): void
    {
        $user = $this->soloOwner();
        $token = $this->asToken($user);

        $this->withBearer($token)->postJson(self::DELETE, $this->body())->assertOk();
        $this->withBearer($token)->postJson(self::DELETE, $this->body())->assertUnauthorized();
        $this->assertSame(1, AuditLog::where('action', 'account.deleted')->count());
    }

    public function test_the_web_cookie_client_can_delete(): void
    {
        $user = $this->soloOwner();

        $this->deleteAs($user)->assertOk();

        $this->assertTrue($user->fresh()->isAnonymised());
    }

    public function test_the_mobile_token_client_can_delete_including_the_code_route(): void
    {
        $user = $this->passwordless();
        $token = $this->asToken($user);

        $this->withBearer($token)->getJson(self::DELETE.'/check')->assertOk()->assertJsonPath('data.has_password', false);
        $this->withBearer($token)->postJson(self::DELETE.'/request-code')->assertOk();
        $code = $this->lastCode($user, OtpPurpose::AccountDeletion);
        $this->withBearer($token)->postJson(self::DELETE, ['confirm' => 'DELETE', 'code' => $code])->assertOk();

        $this->assertTrue($user->fresh()->isAnonymised());
        $this->withBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ platform admin

    public function test_a_platform_admin_deletes_a_user_with_the_same_service_and_blockers(): void
    {
        $admin = $this->admin();
        $user = $this->soloOwner('Sola Sole');

        $this->signInAs($admin)->postJson(self::ADMIN_API.'/users/'.$user->id.'/delete', [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson(self::ADMIN_API.'/users/'.$user->id.'/delete', ['reason' => 'User asked support to delete their account'])->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.already_deleted', false);

        $this->assertTrue($user->fresh()->isAnonymised());
        $entry = AuditLog::where('action', 'platform.user_deleted')->firstOrFail();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertNull($entry->resource_label);
        $this->assertSame('User asked support to delete their account', $entry->changes['reason']);
        Notification::assertSentOnDemandTimes(AccountDeletedNotification::class, 1);

        // repeating is a no-op; the blockers apply as usual
        $this->postJson(self::ADMIN_API.'/users/'.$user->id.'/delete', ['reason' => 'again please'])->assertOk()->assertJsonPath('data.already_deleted', true);
        $this->member(FarmRole::Manager);
        $this->postJson(self::ADMIN_API.'/users/'.$this->owner->id.'/delete', ['reason' => 'blocked case'])->assertStatus(409)
            ->assertJsonPath('details.blockers.0.code', 'owner_must_transfer');
        $this->postJson(self::ADMIN_API.'/users/'.$admin->id.'/delete', ['reason' => 'delete myself'])->assertStatus(409)
            ->assertJsonPath('details.blockers.0.code', 'platform_admin_protected');
        $this->postJson(self::ADMIN_API.'/users/'.Str::uuid().'/delete', ['reason' => 'no such user'])->assertNotFound();
    }

    public function test_a_support_role_and_ordinary_users_cannot_delete_others(): void
    {
        $user = $this->soloOwner();

        $this->signInAs($this->admin(PlatformRole::Support))->postJson(self::ADMIN_API.'/users/'.$user->id.'/delete', ['reason' => 'support cannot'])->assertForbidden();
        $this->signInAs($this->buyer)->postJson(self::ADMIN_API.'/users/'.$user->id.'/delete', ['reason' => 'not an admin'])->assertForbidden();
        $this->assertFalse($user->fresh()->isAnonymised());
    }
}
