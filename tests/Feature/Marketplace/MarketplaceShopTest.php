<?php

namespace Tests\Feature\Marketplace;

use App\Enums\FarmRole;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\MarketplaceShop;
use App\Models\PlatformAdmin;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

class MarketplaceShopTest extends TeamTestCase
{
    private const SELLER = '/api/v1/marketplace';

    private const PUBLIC = '/api/v1/public/marketplace/shops';

    private const ADMIN = '/api/v1/platform-admin/marketplace/shops';

    private const PHONE = '+2348031234567';

    /** A verified account with NO farm and no onboarding: the marketplace-only seller. */
    private function seller(string $name = 'Ada Seller', ?string $email = null): User
    {
        return User::factory()->create(['name' => $name] + ($email ? ['email' => $email] : []));
    }

    private function admin(PlatformRole $role = PlatformRole::Admin): User
    {
        $user = User::factory()->create(['name' => 'Pat Admin']);
        PlatformAdmin::create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'name' => 'Ada Poultry Hub', 'tagline' => 'Fresh eggs daily', 'seller_type' => 'business', 'categories' => ['livestock', 'eggs_dairy'],
            'description' => 'Layers, broilers and fresh crates of eggs delivered across Ibadan.', 'state' => 'Oyo', 'city' => 'Ibadan', 'area' => 'Bodija',
        ], $extra);
    }

    private function contact(array $extra = []): array
    {
        return array_replace(['contact_phone' => self::PHONE, 'contact_whatsapp' => '+2348099998888', 'contact_email' => 'ada.private@example.com', 'address_line' => '12 Secret Street, Bodija', 'preferred_contact_method' => 'whatsapp'], $extra);
    }

    private function createShop(User $user, array $extra = []): string
    {
        return $this->signInAs($user)->postJson(self::SELLER.'/shops', $this->payload($extra))->assertCreated()->json('data.id');
    }

    /** Draft -> complete profile -> submitted. */
    private function submitted(User $user, array $extra = []): string
    {
        $id = $this->createShop($user, $extra);
        $this->signInAs($user)->patchJson(self::SELLER."/shops/$id/contact", $this->contact())->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');

        return $id;
    }

    private function published(User $user, array $extra = [], bool $verify = false): string
    {
        $id = $this->submitted($user, $extra);
        $admin = $this->admin();
        $this->signInAs($admin)->postJson(self::ADMIN."/$id/approve")->assertOk()->assertJsonPath('data.status', 'active');
        if ($verify) {
            $this->signInAs($user)->postJson(self::SELLER."/shops/$id/request-verification")->assertOk();
            $this->signInAs($admin)->postJson(self::ADMIN."/$id/verification", ['decision' => 'verified'])->assertOk();
        }
        $this->app['auth']->forgetGuards();

        return $id;
    }

    private function slug(string $id): string
    {
        return MarketplaceShop::findOrFail($id)->slug;
    }

    // ------------------------------------------------------------------ onboarding

    public function test_a_marketplace_only_seller_creates_a_shop_without_a_farm_or_onboarding(): void
    {
        $user = $this->seller();
        $this->assertFalse($user->isOnboarded());
        $this->assertNull($user->currentFarm());

        $response = $this->signInAs($user)->postJson(self::SELLER.'/shops', $this->payload())->assertCreated();
        $response->assertJsonPath('data.status', 'draft')->assertJsonPath('data.is_public', false)->assertJsonPath('data.farm_backed', false)
            ->assertJsonPath('data.verification.status', 'unverified')->assertJsonPath('data.viewer.role', 'owner')->assertJsonPath('data.slug', 'ada-poultry-hub')
            ->assertJsonPath('data.reference', 'SHP-'.now()->format('Y').'-00001')->assertJsonPath('data.contact_methods', ['in_app']);
        $this->assertContains('shop.manage_members', $response->json('data.viewer.permissions'));

        $this->assertFalse($user->fresh()->isOnboarded(), 'creating a shop never completes farm onboarding');
        $this->assertSame(0, $user->memberships()->count(), 'and never creates a farm membership');
        $this->assertDatabaseCount('farms', 1);   // only the fixture farm

        $this->getJson(self::SELLER.'/my/shops')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.shop_created', 'actor_id' => $user->id, 'resource_id' => $response->json('data.id')]);
    }

    public function test_shop_routes_need_a_signed_in_verified_account(): void
    {
        $this->postJson(self::SELLER.'/shops', $this->payload())->assertUnauthorized();
        $this->getJson(self::SELLER.'/my/shops')->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        $this->signInAs($unverified)->postJson(self::SELLER.'/shops', $this->payload())->assertForbidden()->assertJsonPath('code', 'email_verification_required');

        $suspended = User::factory()->suspended()->create();
        $this->signInAs($suspended)->getJson(self::SELLER.'/my/shops')->assertStatus(403);
    }

    public function test_validation_of_the_shop_payload(): void
    {
        $this->signInAs($this->seller());
        $this->postJson(self::SELLER.'/shops', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'seller_type']);
        $this->postJson(self::SELLER.'/shops', $this->payload(['categories' => ['livestock', 'livestock']]))->assertStatus(422)->assertJsonValidationErrors(['categories.0']);
        $this->postJson(self::SELLER.'/shops', $this->payload(['categories' => ['guns']]))->assertStatus(422)->assertJsonValidationErrors(['categories.0']);
        $this->postJson(self::SELLER.'/shops', $this->payload(['seller_type' => 'wholesaler']))->assertStatus(422)->assertJsonValidationErrors(['seller_type']);
        $this->postJson(self::SELLER.'/shops', $this->payload(['name' => 'A']))->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => 'nope']))->assertStatus(422)->assertJsonValidationErrors(['farm_id']);
        $this->assertSame(0, MarketplaceShop::count());
    }

    public function test_client_cannot_set_lifecycle_or_ownership_fields(): void
    {
        $user = $this->seller();
        $id = $this->signInAs($user)->postJson(self::SELLER.'/shops', $this->payload() + [
            'status' => 'active', 'verification_status' => 'verified', 'approved_at' => now()->toIso8601String(), 'created_by' => $this->owner->id, 'slug' => 'hijack', 'reference' => 'SHP-X',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $shop = MarketplaceShop::findOrFail($id);
        $this->assertSame($user->id, $shop->created_by);
        $this->assertSame('ada-poultry-hub', $shop->slug);
        $this->assertNull($shop->approved_at);
        $this->patchJson(self::SELLER."/shops/$id", ['status' => 'active', 'slug' => 'x', 'farm_id' => $this->farm->id])->assertOk();
        $shop->refresh();
        $this->assertSame('draft', $shop->status->value);
        $this->assertSame('ada-poultry-hub', $shop->slug);
        $this->assertNull($shop->farm_id);
        $this->getJson(self::SELLER.'/shops/'.$id)->assertOk()->assertJsonPath('data.is_public', false);
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_slug_reference_and_name_uniqueness(): void
    {
        $a = $this->seller('A');
        $b = $this->seller('B');
        $this->createShop($a);
        $this->postJson(self::SELLER.'/shops', $this->payload())->assertStatus(422)->assertJsonValidationErrors(['name']);   // same owner, same name

        $second = $this->signInAs($b)->postJson(self::SELLER.'/shops', $this->payload())->assertCreated();
        $second->assertJsonPath('data.slug', 'ada-poultry-hub-2')->assertJsonPath('data.reference', 'SHP-'.now()->format('Y').'-00002');
        $this->postJson(self::SELLER.'/shops', $this->payload(['name' => '!!!']))->assertCreated()->assertJsonPath('data.slug', 'shop');
    }

    public function test_per_user_shop_limit_comes_from_the_platform_setting(): void
    {
        $user = $this->seller();
        $this->signInAs($user);
        foreach (['One', 'Two', 'Three'] as $name) {
            $this->postJson(self::SELLER.'/shops', $this->payload(['name' => "Shop $name"]))->assertCreated();
        }
        $this->postJson(self::SELLER.'/shops', $this->payload(['name' => 'Shop Four']))->assertStatus(409)->assertJsonPath('code', 'shop_limit_reached')->assertJsonPath('details.limit', 3);

        PlatformSetting::create(['key' => 'marketplace_max_shops_per_user', 'value' => ['value' => 4]]);
        $this->postJson(self::SELLER.'/shops', $this->payload(['name' => 'Shop Four']))->assertCreated();
        $this->assertSame(4, MarketplaceShop::where('created_by', $user->id)->count());
    }

    // ------------------------------------------------------------------ farm link

    public function test_an_existing_farm_user_links_a_shop_to_their_farm_subject_to_the_farm_permission(): void
    {
        $response = $this->signInAs($this->owner)->postJson(self::SELLER.'/shops', $this->payload(['seller_type' => 'farm', 'farm_id' => $this->farm->id]))->assertCreated();
        $response->assertJsonPath('data.farm_backed', true);
        $this->assertArrayNotHasKey('farm_id', $response->json('data'), 'the seller resource never exposes the farm id');
        $this->assertSame($this->farm->id, MarketplaceShop::first()->farm_id);

        $this->postJson(self::SELLER.'/shops', $this->payload(['name' => 'Second', 'farm_id' => $this->farm->id]))->assertStatus(409)->assertJsonPath('code', 'farm_shop_exists');

        // Manager is allowed (marketplace.manage); a Farm Worker and a Finance user are not.
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $manager = $this->member(FarmRole::Manager, $otherFarm);
        $this->signInAs($manager)->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $otherFarm->id]))->assertCreated();
        $otherFarm2 = $this->otherFarm()[1];
        foreach ([FarmRole::FarmWorker, FarmRole::Finance, FarmRole::Vet] as $role) {
            $this->signInAs($this->member($role, $otherFarm2))->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $otherFarm2->id]))->assertForbidden();
        }
    }

    public function test_a_client_supplied_farm_id_is_never_trusted(): void
    {
        [, $foreignFarm] = $this->otherFarm();
        $user = $this->signInAs($this->owner);   // owner of $this->farm only

        $foreign = $this->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $foreignFarm->id]))->assertStatus(422);
        $missing = $this->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => (string) Str::uuid7()]))->assertStatus(422);
        $this->assertSame($foreign->json('errors'), $missing->json('errors'), 'a foreign farm and a missing farm answer identically');

        $this->removeMembership($this->owner);   // removed members lose the right immediately
        $this->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $this->farm->id]))->assertStatus(422);
        $this->assertSame(0, MarketplaceShop::count());
    }

    public function test_farm_workflows_and_onboarding_are_unchanged(): void
    {
        $this->signInAs($this->seller())->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'onboarding_required');   // a seller is not a farm user
        $this->signInAs($this->owner)->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $this->farm->id]))->assertCreated();
        $this->getJson('/api/v1/farm')->assertOk();
        $this->postJson('/api/v1/onboarding/farm', ['name' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'already_onboarded');
        $this->assertContains('marketplace.manage', $this->getJson('/api/v1/farm')->json('data.membership.permissions'));
    }

    public function test_a_farmless_seller_is_routed_to_the_marketplace_and_can_still_add_a_farm_later(): void
    {
        $user = $this->seller();
        $this->signInAs($user);

        // Brand new, no shop: farm onboarding is still the default route (existing contract), with a zero marketplace count.
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $me->assertJsonPath('data.next_action', 'complete_farm_setup')->assertJsonPath('data.marketplace.shop_count', 0);

        // Opening a shop moves the route to the marketplace; nothing about the farm state changes.
        $this->postJson(self::SELLER.'/shops', $this->payload())->assertCreated();
        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $me->assertJsonPath('data.next_action', 'marketplace')->assertJsonPath('data.marketplace.shop_count', 1)
            ->assertJsonPath('data.onboarded', false)->assertJsonPath('data.has_active_farm', false)->assertJsonPath('data.farm', null)->assertJsonPath('data.farms', []);
        $this->getJson(self::SELLER.'/my/shops')->assertOk()->assertJsonCount(1, 'data');

        // Farm APIs keep requiring a farm.
        $this->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'onboarding_required');
        $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'onboarding_required');

        // The same account may add a farm later: no second account, shops stay.
        $this->postJson('/api/v1/onboarding/farm', ['name' => 'Ada Farm'])->assertCreated()->assertJsonPath('data.next_action', 'none')
            ->assertJsonPath('data.marketplace.shop_count', 1)->assertJsonPath('data.has_active_farm', true);
        $this->getJson('/api/v1/farm')->assertOk();
        $this->getJson(self::SELLER.'/my/shops')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, User::where('id', $user->id)->count());
    }

    public function test_farm_users_keep_next_action_none_whether_or_not_they_run_a_shop(): void
    {
        $this->signInAs($this->owner)->getJson('/api/v1/auth/me')->assertJsonPath('data.next_action', 'none')->assertJsonPath('data.marketplace.shop_count', 0);
        $this->postJson(self::SELLER.'/shops', $this->payload())->assertCreated();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.next_action', 'none')->assertJsonPath('data.marketplace.shop_count', 1)->assertJsonPath('data.farm.id', $this->farm->id);
    }

    public function test_a_user_who_lost_every_farm_goes_to_the_marketplace_only_when_they_have_a_shop(): void
    {
        $worker = $this->member(FarmRole::FarmWorker);
        $this->removeMembership($worker);
        $this->signInAs($worker)->getJson('/api/v1/auth/me')->assertJsonPath('data.next_action', 'no_active_farm')->assertJsonPath('data.marketplace.shop_count', 0);

        $this->postJson(self::SELLER.'/shops', $this->payload())->assertCreated();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.next_action', 'marketplace')->assertJsonPath('data.onboarded', true);
        $this->getJson('/api/v1/farm')->assertForbidden()->assertJsonPath('code', 'no_active_farm');
    }

    public function test_shop_counts_are_per_user_and_other_peoples_shops_do_not_change_routing(): void
    {
        $this->createShop($this->seller('Seller One', 'one@example.com'));
        $second = $this->seller('Seller Two', 'two@example.com');
        $this->signInAs($second)->getJson('/api/v1/auth/me')->assertJsonPath('data.next_action', 'complete_farm_setup')->assertJsonPath('data.marketplace.shop_count', 0);
        $unverified = User::factory()->unverified()->create();
        $this->signInAs($unverified)->getJson('/api/v1/auth/me')->assertJsonPath('data.next_action', 'verify_email');
    }

    // ------------------------------------------------------------------ profile and private contact

    public function test_profile_update_is_partial_and_audited_by_field_name(): void
    {
        $user = $this->seller();
        $id = $this->createShop($user);

        $this->patchJson(self::SELLER."/shops/$id", ['tagline' => 'New tagline', 'categories' => ['crops']])->assertOk()->assertJsonPath('data.tagline', 'New tagline')->assertJsonPath('data.categories', ['crops'])->assertJsonPath('data.name', 'Ada Poultry Hub');
        $log = AuditLog::where('action', 'marketplace.shop_updated')->firstOrFail();
        $this->assertEqualsCanonicalizing(['tagline', 'categories'], $log->changes['fields']);

        $this->patchJson(self::SELLER."/shops/$id", ['tagline' => 'New tagline'])->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'marketplace.shop_updated')->count(), 'a no-op update writes no audit entry');
        $this->patchJson(self::SELLER."/shops/$id", ['name' => 'Renamed Hub'])->assertOk()->assertJsonPath('data.slug', 'ada-poultry-hub');
    }

    public function test_private_contact_details_are_stored_for_the_seller_and_never_returned_by_public_or_profile_apis(): void
    {
        $user = $this->seller();
        $id = $this->published($user);
        $this->signInAs($user)->patchJson(self::SELLER."/shops/$id/contact", $this->contact())->assertOk()->assertJsonPath('data.contact_phone', self::PHONE)->assertJsonPath('data.address_line', '12 Secret Street, Bodija');
        $this->getJson(self::SELLER."/shops/$id/contact")->assertOk()->assertJsonPath('data.contact_email', 'ada.private@example.com');

        $secrets = [self::PHONE, '2348099998888', 'ada.private@example.com', 'Secret Street', $user->email, $user->id];
        $bodies = [
            $this->getJson(self::SELLER."/shops/$id")->assertOk()->getContent(),
            $this->getJson(self::SELLER.'/my/shops')->assertOk()->getContent(),
            $this->getJson(self::PUBLIC)->assertOk()->getContent(),
            $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk()->getContent(),
        ];
        foreach ($bodies as $body) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->app['auth']->forgetGuards();
        $public = $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk();
        $this->assertSame(['in_app', 'phone', 'whatsapp', 'email'], $public->json('data.contact_methods'));
        $this->assertEqualsCanonicalizing(['id', 'slug', 'name', 'tagline', 'description', 'seller_type', 'categories', 'location', 'verified', 'verified_at', 'farm_backed', 'contact_methods', 'member_since'], array_keys($public->json('data')));

        // Audit stores which fields changed, never their values.
        $audit = AuditLog::where('action', 'marketplace.shop_contact_updated')->latest('created_at')->firstOrFail();
        $this->assertStringNotContainsString(self::PHONE, json_encode($audit->changes));
        $this->assertStringNotContainsString('Secret Street', json_encode($audit->changes));
    }

    public function test_contact_validation(): void
    {
        $id = $this->createShop($this->seller());
        $this->patchJson(self::SELLER."/shops/$id/contact", ['contact_phone' => '0803-bad'])->assertStatus(422)->assertJsonValidationErrors(['contact_phone']);
        $this->patchJson(self::SELLER."/shops/$id/contact", ['contact_email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['contact_email']);
        $this->patchJson(self::SELLER."/shops/$id/contact", ['preferred_contact_method' => 'carrier_pigeon'])->assertStatus(422)->assertJsonValidationErrors(['preferred_contact_method']);
        $this->patchJson(self::SELLER."/shops/$id/contact", ['preferred_contact_method' => 'whatsapp'])->assertStatus(422)->assertJsonValidationErrors(['preferred_contact_method']);   // channel not configured
        $this->patchJson(self::SELLER."/shops/$id/contact", ['preferred_contact_method' => 'in_app'])->assertOk();
        $this->patchJson(self::SELLER."/shops/$id/contact", ['contact_phone' => self::PHONE, 'preferred_contact_method' => 'phone'])->assertOk();
        $this->patchJson(self::SELLER."/shops/$id/contact", ['contact_phone' => null])->assertStatus(422);   // would orphan the preferred channel
    }

    // ------------------------------------------------------------------ lifecycle and visibility

    public function test_a_new_shop_is_not_public_until_a_platform_admin_approves_it(): void
    {
        $user = $this->seller();
        $id = $this->createShop($user);
        $slug = $this->slug($id);
        $this->app['auth']->forgetGuards();

        // draft
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::PUBLIC."/$slug")->assertNotFound();

        // submit needs a complete profile
        $this->signInAs($user)->patchJson(self::SELLER."/shops/$id", ['description' => 'Too short', 'state' => null, 'city' => null, 'categories' => []])->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertStatus(422)->assertJsonPath('code', 'shop_incomplete')->assertJsonPath('details.missing', ['description', 'state', 'city', 'categories', 'contact']);
        $this->patchJson(self::SELLER."/shops/$id", $this->payload())->assertOk();
        $this->patchJson(self::SELLER."/shops/$id/contact", $this->contact())->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.is_public', false);
        $this->postJson(self::SELLER."/shops/$id/submit")->assertStatus(409)->assertJsonPath('code', 'invalid_shop_state');

        // pending_review: still invisible, and the seller cannot approve, reopen or self-verify their way past review
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/$slug")->assertNotFound();
        $this->signInAs($user)->postJson(self::SELLER."/shops/$id/reopen")->assertStatus(409);
        $this->postJson(self::SELLER."/shops/$id/request-verification")->assertStatus(409)->assertJsonPath('code', 'invalid_shop_state');
        $this->postJson(self::ADMIN."/$id/approve")->assertForbidden()->assertJsonPath('code', 'platform_admin_required');

        // approved
        $this->signInAs($this->admin())->postJson(self::ADMIN."/$id/approve")->assertOk()->assertJsonPath('data.status', 'active');
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/$slug")->assertOk()->assertJsonPath('data.name', 'Ada Poultry Hub')->assertJsonPath('data.verified', false);
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull(MarketplaceShop::find($id)->approved_at);
    }

    public function test_rejection_returns_the_shop_to_the_seller_with_a_reason_and_it_can_resubmit(): void
    {
        $user = $this->seller();
        $id = $this->submitted($user);
        $admin = $this->admin();

        $this->signInAs($admin)->postJson(self::ADMIN."/$id/reject", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson(self::ADMIN."/$id/reject", ['reason' => 'Description is misleading'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson(self::ADMIN."/$id/reject", ['reason' => 'Again please'])->assertStatus(409);   // no longer pending

        $this->signInAs($user)->getJson(self::SELLER."/shops/$id")->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.status_reason', 'Description is misleading');
        $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertNotFound();
        $this->patchJson(self::SELLER."/shops/$id", ['description' => 'Honest, accurate description of the poultry hub and its eggs.'])->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.status_reason', null);
    }

    public function test_suspension_hides_the_shop_freezes_seller_changes_and_cannot_be_bypassed(): void
    {
        $user = $this->seller();
        $id = $this->published($user);
        $slug = $this->slug($id);
        $admin = $this->admin();

        $this->signInAs($admin)->postJson(self::ADMIN."/$id/suspend", ['reason' => 'Fraud reports'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson(self::ADMIN."/$id/suspend", ['reason' => 'Twice'])->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/$slug")->assertNotFound();
        $this->getJson(self::PUBLIC)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::PUBLIC.'?q=Ada')->assertOk()->assertJsonCount(0, 'data');

        $this->signInAs($user)->getJson(self::SELLER."/shops/$id")->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Fraud reports')->assertJsonPath('data.is_public', false);
        foreach ([
            ['patch', "/shops/$id", ['name' => 'Sneaky']], ['patch', "/shops/$id/contact", ['contact_phone' => '+2348000000000']],
            ['post', "/shops/$id/submit", []], ['post', "/shops/$id/reopen", []], ['post', "/shops/$id/close", []], ['post', "/shops/$id/request-verification", []],
            ['post', "/shops/$id/members", ['email' => $this->seller('Bo', 'bo@example.com')->email, 'role' => 'staff']],
        ] as [$method, $path, $body]) {
            $this->{$method.'Json'}(self::SELLER.$path, $body)->assertStatus(409)->assertJsonPath('code', 'shop_suspended');
        }

        $this->signInAs($admin)->postJson(self::ADMIN."/$id/reinstate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson(self::ADMIN."/$id/reinstate")->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/$slug")->assertOk();
    }

    public function test_suspending_an_unapproved_shop_never_grants_approval(): void
    {
        $id = $this->submitted($this->seller());
        $admin = $this->admin();
        $this->signInAs($admin)->postJson(self::ADMIN."/$id/suspend", ['reason' => 'Looks off'])->assertOk();
        $this->postJson(self::ADMIN."/$id/reinstate")->assertOk()->assertJsonPath('data.status', 'pending_review');
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertNotFound();

        $draft = $this->createShop($this->seller('Other', 'other@example.com'), ['name' => 'Draft Shop']);
        $this->signInAs($admin)->postJson(self::ADMIN."/$draft/suspend", ['reason' => 'Not now'])->assertStatus(409)->assertJsonPath('code', 'invalid_shop_state');
        $this->postJson(self::ADMIN."/$draft/approve")->assertStatus(409);
    }

    public function test_seller_can_close_and_reopen_an_approved_shop(): void
    {
        $user = $this->seller();
        $id = $this->published($user);
        $slug = $this->slug($id);

        $this->signInAs($user)->postJson(self::SELLER."/shops/$id/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson(self::SELLER."/shops/$id/close")->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/$slug")->assertNotFound();
        $this->signInAs($user)->postJson(self::SELLER."/shops/$id/reopen")->assertOk()->assertJsonPath('data.status', 'active');
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC."/$slug")->assertOk();
    }

    public function test_verification_is_a_separate_badge_with_its_own_workflow(): void
    {
        $user = $this->seller();
        $id = $this->published($user);
        $admin = $this->admin();
        $this->signInAs($admin)->postJson(self::ADMIN."/$id/verification", ['decision' => 'unverified', 'reason' => 'Not verified'])->assertStatus(409)->assertJsonPath('code', 'invalid_verification_state');
        $this->postJson(self::ADMIN."/$id/verification", ['decision' => 'rejected'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson(self::ADMIN."/$id/verification", ['decision' => 'maybe'])->assertStatus(422);

        $this->signInAs($user)->postJson(self::SELLER."/shops/$id/request-verification")->assertOk()->assertJsonPath('data.verification.status', 'pending');
        $this->postJson(self::SELLER."/shops/$id/request-verification")->assertStatus(409)->assertJsonPath('code', 'verification_not_requestable');

        $this->signInAs($admin)->postJson(self::ADMIN."/$id/verification", ['decision' => 'rejected', 'reason' => 'ID unclear'])->assertOk()->assertJsonPath('data.verification.status', 'rejected');
        $this->signInAs($user)->getJson(self::SELLER."/shops/$id")->assertJsonPath('data.verification.reason', 'ID unclear');
        $this->postJson(self::SELLER."/shops/$id/request-verification")->assertOk();
        $this->signInAs($admin)->postJson(self::ADMIN."/$id/verification", ['decision' => 'verified'])->assertOk()->assertJsonPath('data.verification.status', 'verified')->assertJsonPath('data.status', 'active');

        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertJsonPath('data.verified', true);
        $this->getJson(self::PUBLIC.'?verified=true')->assertJsonCount(1, 'data');
        $this->getJson(self::PUBLIC.'?verified=false')->assertJsonCount(0, 'data');

        $this->signInAs($admin)->postJson(self::ADMIN."/$id/verification", ['decision' => 'unverified', 'reason' => 'Documents expired'])->assertOk()->assertJsonPath('data.verification.status', 'unverified');
        $this->app['auth']->forgetGuards();
        $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertJsonPath('data.verified', false);

        // The badge needs an approved shop.
        $pending = $this->submitted($this->seller('Z', 'z@example.com'), ['name' => 'Unapproved']);
        $this->signInAs($admin)->postJson(self::ADMIN."/$pending/verification", ['decision' => 'verified'])->assertStatus(409)->assertJsonPath('code', 'shop_not_approved');
    }

    // ------------------------------------------------------------------ shop ownership and permissions

    public function test_only_members_reach_a_shop_and_the_role_decides_the_action(): void
    {
        $owner = $this->seller('Owner', 'owner@example.com');
        $id = $this->createShop($owner);
        $outsider = $this->seller('Out', 'out@example.com');
        $manager = $this->seller('Mgr', 'mgr@example.com');
        $staff = $this->seller('Stf', 'stf@example.com');

        $this->signInAs($owner);
        $managerMember = $this->postJson(self::SELLER."/shops/$id/members", ['email' => 'MGR@example.com', 'role' => 'manager'])->assertCreated()->assertJsonPath('data.role', 'manager')->json('data.id');
        $this->postJson(self::SELLER."/shops/$id/members", ['email' => 'stf@example.com', 'role' => 'staff'])->assertCreated();
        $this->postJson(self::SELLER."/shops/$id/members", ['email' => 'stf@example.com', 'role' => 'staff'])->assertStatus(409)->assertJsonPath('code', 'already_member');
        $this->postJson(self::SELLER."/shops/$id/members", ['email' => 'out@example.com', 'role' => 'owner'])->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->getJson(self::SELLER."/shops/$id/members")->assertOk()->assertJsonCount(3, 'data');

        // Outsider (a different seller, and a farm Owner): the shop does not exist for them.
        foreach ([$outsider, $this->owner] as $stranger) {
            $this->signInAs($stranger);
            $this->getJson(self::SELLER."/shops/$id")->assertNotFound();
            $this->patchJson(self::SELLER."/shops/$id", ['name' => 'Hacked'])->assertNotFound();
            $this->getJson(self::SELLER."/shops/$id/contact")->assertNotFound();
            $this->postJson(self::SELLER."/shops/$id/submit")->assertNotFound();
            $this->getJson(self::SELLER."/shops/$id/members")->assertNotFound();
            $this->getJson(self::SELLER.'/my/shops')->assertOk()->assertJsonCount(0, 'data');
        }

        // Manager: profile + contact, but no lifecycle and no members.
        $this->signInAs($manager);
        $this->getJson(self::SELLER."/shops/$id")->assertOk()->assertJsonPath('data.viewer.role', 'manager');
        $this->patchJson(self::SELLER."/shops/$id", ['tagline' => 'By manager'])->assertOk();
        $this->patchJson(self::SELLER."/shops/$id/contact", $this->contact())->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertForbidden();
        $this->postJson(self::SELLER."/shops/$id/request-verification")->assertForbidden();
        $this->getJson(self::SELLER."/shops/$id/members")->assertForbidden();
        $this->postJson(self::SELLER."/shops/$id/members", ['email' => 'out@example.com', 'role' => 'staff'])->assertForbidden();
        $this->deleteJson(self::SELLER."/shops/$id/members/$managerMember")->assertForbidden();

        // Staff: read-only on the shop itself (Phase 23 adds draft-listing rights), and cannot read private contact.
        $this->signInAs($staff);
        $this->getJson(self::SELLER."/shops/$id")->assertOk()->assertJsonPath('data.viewer.permissions', ['shop.view', 'listing.view', 'listing.manage', 'offer.view', 'deal.view']);
        $this->getJson(self::SELLER."/shops/$id/contact")->assertForbidden();
        $this->patchJson(self::SELLER."/shops/$id", ['tagline' => 'x'])->assertForbidden();
        $this->patchJson(self::SELLER."/shops/$id/contact", ['contact_phone' => '+2348000000000'])->assertForbidden();
        $this->assertStringNotContainsString(self::PHONE, $this->getJson(self::SELLER."/shops/$id")->getContent());
        $this->getJson(self::SELLER.'/my/shops')->assertOk()->assertJsonCount(1, 'data');

        // A farm role confers nothing on a shop; the only farm right is linking a shop at creation.
        $this->assertSame(0, $manager->memberships()->count());
    }

    public function test_member_management_rules(): void
    {
        $owner = $this->seller('Owner', 'owner@example.com');
        $id = $this->createShop($owner);
        $this->seller('Staff', 'staff@example.com');
        $this->signInAs($owner);

        // Unknown, unverified and suspended accounts all answer alike: existence is not revealed.
        User::factory()->unverified()->create(['email' => 'unverified@example.com']);
        User::factory()->suspended()->create(['email' => 'suspended@example.com']);
        $answers = array_map(fn ($email) => $this->postJson(self::SELLER."/shops/$id/members", ['email' => $email, 'role' => 'staff'])->assertStatus(422)->json(), ['nobody@example.com', 'unverified@example.com', 'suspended@example.com']);
        $strip = fn (array $r) => array_diff_key($r, ['request_id' => 1]);
        $this->assertSame($strip($answers[0]), $strip($answers[1]));
        $this->assertSame($strip($answers[0]), $strip($answers[2]));

        $member = $this->postJson(self::SELLER."/shops/$id/members", ['email' => 'staff@example.com', 'role' => 'staff'])->assertCreated()->json('data.id');
        $this->patchJson(self::SELLER."/shops/$id/members/$member", ['role' => 'manager'])->assertOk()->assertJsonPath('data.role', 'manager');
        $this->patchJson(self::SELLER."/shops/$id/members/$member", ['role' => 'owner'])->assertStatus(422);

        $ownerRow = $this->getJson(self::SELLER."/shops/$id/members")->json('data.0.id');
        $this->patchJson(self::SELLER."/shops/$id/members/$ownerRow", ['role' => 'staff'])->assertStatus(409)->assertJsonPath('code', 'owner_protected');
        $this->deleteJson(self::SELLER."/shops/$id/members/$ownerRow")->assertStatus(409)->assertJsonPath('code', 'owner_protected');

        $this->deleteJson(self::SELLER."/shops/$id/members/$member")->assertOk();
        $this->deleteJson(self::SELLER."/shops/$id/members/$member")->assertNotFound();
        $this->signInAs(User::where('email', 'staff@example.com')->first())->getJson(self::SELLER."/shops/$id")->assertNotFound();   // access ends immediately

        // A member id from another shop is not addressable through this one.
        $otherShop = $this->signInAs($owner)->postJson(self::SELLER.'/shops', $this->payload(['name' => 'Second Hub']))->assertCreated()->json('data.id');
        $this->deleteJson(self::SELLER."/shops/$otherShop/members/$ownerRow")->assertStatus(404);
        $this->assertSame(2, collect([$id, $otherShop])->filter(fn ($s) => $this->getJson(self::SELLER."/shops/$s/members")->assertOk()->json('data.0.role') === 'owner')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.member_role_changed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.member_removed']);
    }

    // ------------------------------------------------------------------ public discovery

    public function test_public_discovery_filters_sorts_and_paginates_active_shops_only(): void
    {
        $verified = $this->published($this->seller('A', 'a@example.com'), ['name' => 'Alpha Farms', 'state' => 'Lagos', 'city' => 'Ikeja', 'categories' => ['crops'], 'seller_type' => 'farm'], verify: true);
        $this->published($this->seller('B', 'b@example.com'), ['name' => 'Bravo Feeds', 'state' => 'Oyo', 'city' => 'Ibadan', 'categories' => ['feed_inputs']]);
        $hidden = $this->published($this->seller('C', 'c@example.com'), ['name' => 'Charlie Cattle', 'categories' => ['livestock']]);
        $this->submitted($this->seller('D', 'd@example.com'), ['name' => 'Delta Pending']);
        $this->createShop($this->seller('E', 'e@example.com'), ['name' => 'Echo Draft']);
        $this->signInAs($this->admin())->postJson(self::ADMIN."/$hidden/suspend", ['reason' => 'Complaints'])->assertOk();
        $this->app['auth']->forgetGuards();

        $names = fn (string $query = '') => array_column($this->getJson(self::PUBLIC.$query)->assertOk()->json('data'), 'name');
        $this->assertEqualsCanonicalizing(['Alpha Farms', 'Bravo Feeds'], $names());
        $this->assertSame(['Alpha Farms', 'Bravo Feeds'], $names('?sort=name'));
        $this->assertSame(['Alpha Farms'], $names('?state=Lagos'));
        $this->assertSame(['Bravo Feeds'], $names('?city=Ibadan'));
        $this->assertSame(['Bravo Feeds'], $names('?category=feed_inputs'));
        $this->assertSame(['Alpha Farms'], $names('?seller_type=farm'));
        $this->assertSame(['Alpha Farms'], $names('?verified=1'));
        $this->assertSame(['Bravo Feeds'], $names('?verified=0'));
        $this->assertSame(['Bravo Feeds'], $names('?q=feeds'));
        $this->assertSame([], $names('?q=Charlie'));
        $this->assertSame([], $names('?q=Delta'));
        $this->assertSame([], $names('?q=%25'), 'LIKE wildcards are escaped');

        $page = $this->getJson(self::PUBLIC.'?per_page=1&sort=name')->assertOk();
        $page->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
        $this->getJson(self::PUBLIC.'?per_page=500')->assertStatus(422);
        $this->getJson(self::PUBLIC.'?category=bogus')->assertStatus(422);

        $this->getJson(self::PUBLIC.'/'.$this->slug($hidden))->assertNotFound();
        $this->getJson(self::PUBLIC.'/'.$this->slug($verified))->assertOk()->assertJsonPath('data.verified', true)->assertJsonPath('data.location.city', 'Ikeja');
        $this->getJson(self::PUBLIC.'/does-not-exist')->assertNotFound();
        $this->getJson(self::PUBLIC.'/'.$verified)->assertNotFound();   // addressed by slug, not id
    }

    public function test_public_responses_never_leak_internal_or_owner_data_for_farm_backed_shops(): void
    {
        $this->signInAs($this->owner)->postJson(self::SELLER.'/shops', $this->payload(['seller_type' => 'farm', 'farm_id' => $this->farm->id]))->assertCreated();
        $id = MarketplaceShop::first()->id;
        $this->patchJson(self::SELLER."/shops/$id/contact", $this->contact())->assertOk();
        $this->postJson(self::SELLER."/shops/$id/submit")->assertOk();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/$id/approve")->assertOk();
        $this->app['auth']->forgetGuards();

        $body = $this->getJson(self::PUBLIC)->assertOk()->getContent().$this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk()->getContent();
        foreach ([$this->farm->id, $this->owner->id, $this->owner->email, 'Green Acres', self::PHONE, 'ada.private@example.com', 'Secret Street', 'status_reason', 'created_by'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringContainsString('"farm_backed":true', $body);
    }

    // ------------------------------------------------------------------ platform oversight

    public function test_platform_oversight_is_restricted_to_platform_roles(): void
    {
        $user = $this->seller();
        $id = $this->submitted($user);

        foreach ([$user, $this->owner] as $nonAdmin) {   // a shop owner and a farm Owner
            $this->signInAs($nonAdmin);
            $this->getJson(self::ADMIN)->assertForbidden()->assertJsonPath('code', 'platform_admin_required');
            $this->postJson(self::ADMIN."/$id/approve")->assertForbidden();
        }

        $support = $this->admin(PlatformRole::Support);
        $this->signInAs($support)->getJson(self::ADMIN)->assertOk();
        $this->getJson(self::ADMIN."/$id")->assertOk();
        foreach (['approve' => [], 'reject' => ['reason' => 'nope'], 'suspend' => ['reason' => 'nope'], 'reinstate' => [], 'verification' => ['decision' => 'verified']] as $action => $body) {
            $this->postJson(self::ADMIN."/$id/$action", $body)->assertForbidden()->assertJsonPath('code', 'platform_write_forbidden');
        }
        $this->assertSame('pending_review', MarketplaceShop::find($id)->status->value);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ADMIN)->assertUnauthorized();
    }

    public function test_platform_list_filters_queue_and_detail_shape(): void
    {
        $a = $this->submitted($this->seller('A', 'a@example.com'), ['name' => 'Queue First']);
        $b = $this->submitted($this->seller('B', 'b@example.com'), ['name' => 'Queue Second']);
        MarketplaceShop::whereKey($a)->update(['submitted_at' => now()->subDay()]);
        $this->createShop($this->seller('C', 'c@example.com'), ['name' => 'Just A Draft']);
        $this->signInAs($this->owner)->postJson(self::SELLER.'/shops', $this->payload(['name' => 'Farm Backed', 'farm_id' => $this->farm->id]))->assertCreated();

        $this->signInAs($this->admin());
        $list = fn (string $q = '') => $this->getJson(self::ADMIN.$q)->assertOk();
        $list()->assertJsonPath('meta.total', 4);
        $this->assertSame(['Queue First', 'Queue Second'], array_column($list('?status=pending_review')->json('data'), 'name'), 'review queue is oldest first');
        $this->assertSame(['Farm Backed'], array_column($list('?farm_backed=1')->json('data'), 'name'));
        $this->assertSame(['Just A Draft'], array_column($list('?status=draft&farm_backed=0')->json('data'), 'name'));
        $this->assertSame(['Queue Second'], array_column($list('?q=second')->json('data'), 'name'));
        $this->getJson(self::ADMIN.'?status=bogus')->assertStatus(422);

        $row = $list('?status=pending_review')->json('data.0');
        $this->assertSame('a@example.com', $row['owner']['email']);
        $this->assertArrayNotHasKey('contact', $row, 'lists never carry private contact values');
        $detail = $this->getJson(self::ADMIN."/$b")->assertOk();
        $detail->assertJsonPath('data.contact.contact_phone', self::PHONE)->assertJsonPath('data.owner.name', 'B')->assertJsonPath('data.reference', MarketplaceShop::find($b)->reference);
        $this->getJson(self::ADMIN.'/'.Str::uuid7())->assertNotFound();
    }

    public function test_moderation_decisions_are_audited_and_visible_in_the_platform_audit_trail(): void
    {
        $user = $this->seller();
        $id = $this->submitted($user);
        $admin = $this->admin();

        $this->signInAs($admin)->postJson(self::ADMIN."/$id/approve")->assertOk();
        $this->postJson(self::ADMIN."/$id/suspend", ['reason' => 'Policy breach'])->assertOk();
        $this->postJson(self::ADMIN."/$id/reinstate")->assertOk();

        $entries = $this->getJson('/api/v1/platform-admin/audit-logs?per_page=100')->assertOk()->json('data');
        $actions = array_column($entries, 'action');
        foreach (['platform.marketplace_shop_approved', 'platform.marketplace_shop_suspended', 'platform.marketplace_shop_reinstated'] as $action) {
            $this->assertContains($action, $actions);
        }
        $suspended = AuditLog::where('action', 'platform.marketplace_shop_suspended')->firstOrFail();
        $this->assertSame($admin->id, $suspended->actor_id);
        $this->assertNull($suspended->farm_id);
        $this->assertSame('Policy breach', $suspended->changes['reason']);
        $this->assertSame(['status' => 'active'], $suspended->changes['before']);
        $this->assertSame(['status' => 'suspended'], $suspended->changes['after']);
    }

    public function test_a_farm_backed_shops_events_reach_that_farms_audit_trail_without_private_values(): void
    {
        $id = $this->signInAs($this->owner)->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $this->farm->id]))->assertCreated()->json('data.id');
        $this->patchJson(self::SELLER."/shops/$id/contact", $this->contact())->assertOk();

        $log = AuditLog::where('action', 'marketplace.shop_contact_updated')->firstOrFail();
        $this->assertSame($this->farm->id, $log->farm_id);
        $this->assertStringNotContainsString(self::PHONE, json_encode($log->toArray()));
        // A different farm never sees it.
        $this->assertSame(0, AuditLog::where('farm_id', $this->otherFarm()[1]->id)->count());
    }

    public function test_shop_creation_leaves_farm_data_and_other_tenants_untouched(): void
    {
        [, $foreign] = $this->otherFarm();
        $before = [\DB::table('farms')->count(), \DB::table('farm_memberships')->count(), \DB::table('subscriptions')->count()];

        $this->signInAs($this->owner)->postJson(self::SELLER.'/shops', $this->payload(['farm_id' => $this->farm->id]))->assertCreated();
        $this->signInAs($this->seller())->postJson(self::SELLER.'/shops', $this->payload())->assertCreated();

        $this->assertSame($before, [\DB::table('farms')->count(), \DB::table('farm_memberships')->count(), \DB::table('subscriptions')->count()]);
        $this->assertNull(MarketplaceShop::where('farm_id', $foreign->id)->first());
    }
}
