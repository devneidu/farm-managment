<?php

namespace Tests\Feature\Marketplace;

use App\Enums\FarmRole;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingEvent;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Lifecycle, roles, concurrency, shop visibility and platform moderation of listings. */
class MarketplaceListingLifecycleTest extends ListingTestCase
{
    private function path(string $shop, string $listing, string $suffix = ''): string
    {
        return self::SELLER."/shops/$shop/listings/$listing".$suffix;
    }

    // ------------------------------------------------------------------ publishing

    public function test_an_active_shop_publishes_immediately_without_admin_approval_and_without_an_image(): void
    {
        $user = $this->seller();
        $shop = $this->activeShop($user);
        $id = $this->createId($shop, $this->chicken());

        $this->assertNotContains($this->slug($id), $this->publicSlugs());

        $this->publish($shop, $id)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.is_public', true)->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.image.source', 'placeholder');   // an image-less listing is valid
        $this->assertNotNull(MarketplaceListing::find($id)->published_at);
        $this->assertContains($this->slug($id), $this->publicSlugs());
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.listing_published', 'actor_id' => $user->id, 'resource_id' => $id]);
        $this->assertDatabaseHas('marketplace_listing_events', ['listing_id' => $id, 'action' => 'published', 'from_status' => 'draft', 'to_status' => 'published']);
        $this->assertSame(0, InventoryMovement::count(), 'publishing never touches inventory');
    }

    public function test_only_an_active_shop_may_publish(): void
    {
        $user = $this->seller();
        $draft = $this->draftShop($user);
        $id = $this->createId($draft, $this->chicken());
        $this->publish($draft, $id)->assertStatus(409)->assertJsonPath('code', 'shop_not_active')->assertJsonPath('details.shop_status', 'draft');

        $pending = $this->signInAs($user)->postJson(self::SELLER.'/shops', ['name' => 'Pending Shop', 'seller_type' => 'individual', 'description' => 'A long enough description here.', 'state' => 'Oyo', 'city' => 'Ibadan', 'categories' => ['crops']])->json('data.id');
        $this->patchJson(self::SELLER."/shops/$pending/contact", ['contact_phone' => self::PHONE])->assertOk();
        $this->postJson(self::SELLER."/shops/$pending/submit")->assertOk();
        $p = $this->createId($pending, $this->yam());
        $this->publish($pending, $p)->assertStatus(409)->assertJsonPath('code', 'shop_not_active')->assertJsonPath('details.shop_status', 'pending_review');

        $active = $this->activeShop($user);
        $a = $this->live($active, $this->chicken());
        $this->postJson(self::SELLER."/shops/$active/close")->assertOk();
        $b = $this->createId($active, $this->yam());
        $this->publish($active, $b)->assertStatus(409)->assertJsonPath('details.shop_status', 'closed');
        $this->assertNotContains($this->slug($a), $this->publicSlugs(), 'closing the shop hides its published listings');
    }

    public function test_publishing_needs_a_complete_listing_but_never_an_image(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->createId($shop, $this->catfish(['delivery_coverage' => null, 'delivery_charge' => null, 'state' => null, 'city' => null, 'area' => null]) + []);
        // the shop location was defaulted into state/city/area; clear it explicitly to test the gate
        $this->patchJson($this->path($shop, $id), ['state' => null, 'city' => null, 'area' => null])->assertOk();
        $r = $this->publish($shop, $id)->assertStatus(422)->assertJsonPath('code', 'listing_incomplete');
        $this->assertEqualsCanonicalizing(['state', 'pickup_area', 'delivery_coverage', 'delivery_charge'], $r->json('details.missing'));

        $this->patchJson($this->path($shop, $id), ['state' => 'Oyo', 'pickup_area' => 'Bodija market', 'delivery_coverage' => ['Oyo'], 'delivery_charge' => 'included'])->assertOk();
        $this->publish($shop, $id)->assertOk()->assertJsonPath('data.status', 'published');

        $pickup = $this->createId($shop, $this->chicken(['pickup_area' => null, 'city' => null, 'area' => null]));
        $this->patchJson($this->path($shop, $pickup), ['city' => null, 'area' => null])->assertOk();
        $this->publish($shop, $pickup)->assertStatus(422)->assertJsonPath('details.missing', ['pickup_area']);
    }

    // ------------------------------------------------------------------ transitions

    public function test_the_full_lifecycle_with_valid_and_invalid_transitions(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->createId($shop, $this->chicken());

        $this->postJson($this->path($shop, $id, '/pause'))->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state')->assertJsonPath('details.status', 'draft');   // draft cannot be paused
        $this->postJson($this->path($shop, $id, '/restore'))->assertOk()->assertJsonPath('data.status', 'draft');   // idempotent: already a draft
        $this->publish($shop, $id)->assertOk();
        $this->postJson($this->path($shop, $id, '/restore'))->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state');   // published cannot be "restored"
        $this->postJson($this->path($shop, $id, '/pause'))->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.is_public', false);
        $this->assertNotContains($this->slug($id), $this->publicSlugs());
        $this->publish($shop, $id)->assertOk()->assertJsonPath('data.status', 'published');   // paused -> published
        $this->postJson($this->path($shop, $id, '/archive'))->assertOk()->assertJsonPath('data.status', 'archived');
        $this->publish($shop, $id)->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state');           // archived must be restored first
        $this->postJson($this->path($shop, $id, '/pause'))->assertStatus(409);
        $this->patchJson($this->path($shop, $id), ['title' => 'Edited while archived'])->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state');
        $this->postJson($this->path($shop, $id, '/restore'))->assertOk()->assertJsonPath('data.status', 'draft');
        $this->publish($shop, $id)->assertOk();

        $actions = MarketplaceListingEvent::where('listing_id', $id)->orderBy('created_at')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['created', 'published', 'paused', 'published', 'archived', 'restored', 'published'], $actions);
        $history = $this->getJson($this->path($shop, $id))->assertOk()->json('data.history');
        $this->assertSame($actions, array_column($history, 'action'));
    }

    public function test_repeating_a_transition_is_idempotent_and_writes_no_extra_history(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->createId($shop, $this->chicken());
        $first = $this->publish($shop, $id)->assertOk()->json('data.version');
        $again = $this->publish($shop, $id)->assertOk()->assertJsonPath('data.status', 'published')->json('data.version');
        $this->assertSame($first, $again, 'a repeat does not bump the version');
        $this->assertSame(1, MarketplaceListingEvent::where('listing_id', $id)->where('action', 'published')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.listing_published')->where('resource_id', $id)->count());

        $this->postJson($this->path($shop, $id, '/pause'))->assertOk();
        $this->postJson($this->path($shop, $id, '/pause'))->assertOk();
        $this->assertSame(1, MarketplaceListingEvent::where('listing_id', $id)->where('action', 'paused')->count());
        $this->postJson($this->path($shop, $id, '/archive'))->assertOk();
        $this->postJson($this->path($shop, $id, '/archive'))->assertOk();
        $this->assertSame(1, MarketplaceListingEvent::where('listing_id', $id)->where('action', 'archived')->count());
    }

    public function test_a_stale_version_is_refused_on_updates_and_transitions(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->createId($shop, $this->chicken());   // version 1

        $this->patchJson($this->path($shop, $id), ['unit_price' => '9000', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        // a second client still holding version 1
        $this->patchJson($this->path($shop, $id), ['unit_price' => '7000', 'version' => 1])->assertStatus(409)->assertJsonPath('code', 'stale_listing')->assertJsonPath('details.current_version', 2);
        $this->publish($shop, $id, ['version' => 1])->assertStatus(409)->assertJsonPath('code', 'stale_listing');
        $this->assertSame('9000.00', MarketplaceListing::find($id)->unit_price, 'the stale write changed nothing');
        $this->assertSame('draft', MarketplaceListing::find($id)->status->value);

        $this->publish($shop, $id, ['version' => 2])->assertOk()->assertJsonPath('data.version', 3);
        $this->postJson($this->path($shop, $id, '/pause'), ['version' => 2])->assertStatus(409)->assertJsonPath('code', 'stale_listing');
        $this->postJson($this->path($shop, $id, '/pause'), ['version' => 3])->assertOk();
        $this->patchJson($this->path($shop, $id), ['version' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('version');
    }

    public function test_editing_a_published_listing_keeps_it_live(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->live($shop, $this->chicken());
        $this->patchJson($this->path($shop, $id), ['unit_price' => '8500', 'available_quantity' => '80'])->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson(self::PUBLIC.'/'.$this->slug($id))->assertOk()->assertJsonPath('data.price.amount', '8500.00')->assertJsonPath('data.quantity.available', '80');
    }

    public function test_only_a_draft_can_be_deleted_and_its_history_survives(): void
    {
        $user = $this->seller();
        $shop = $this->activeShop($user);
        $draft = $this->createId($shop, $this->yam());
        $slug = $this->slug($draft);

        $this->deleteJson($this->path($shop, $draft))->assertOk();
        $this->getJson($this->path($shop, $draft))->assertNotFound();
        $this->getJson(self::PUBLIC."/$slug")->assertNotFound();
        $this->assertSoftDeleted('marketplace_listings', ['id' => $draft]);
        $this->assertDatabaseHas('marketplace_listing_events', ['listing_id' => $draft, 'action' => 'deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketplace.listing_deleted', 'resource_id' => $draft]);
        $this->getJson(self::SELLER."/shops/$shop/listings")->assertOk()->assertJsonCount(0, 'data');

        $live = $this->live($shop, $this->chicken());
        $this->deleteJson($this->path($shop, $live))->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state');
        $this->assertNotSoftDeleted('marketplace_listings', ['id' => $live]);

        // history rows are append-only
        $this->expectException(\LogicException::class);
        MarketplaceListingEvent::where('listing_id', $draft)->firstOrFail()->delete();
    }

    // ------------------------------------------------------------------ roles

    public function test_staff_prepare_drafts_but_never_publish_archive_restore_or_edit_live_listings(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $staff = $this->addMember($owner, $shop, 'staff', 'Staffer');
        $live = $this->live($shop, $this->chicken(['title' => 'Owner live listing']));

        $this->signInAs($staff);
        $this->getJson(self::SELLER."/shops/$shop/listings")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path($shop, $live))->assertOk()->assertJsonPath('data.abilities.publish', false)->assertJsonPath('data.abilities.edit', false);

        $draft = $this->createId($shop, $this->yam());
        $this->assertSame($staff->id, MarketplaceListing::find($draft)->created_by);
        $this->patchJson($this->path($shop, $draft), ['unit_price' => '2100'])->assertOk()->assertJsonPath('data.abilities.edit', true);
        $this->postJson($this->path($shop, $draft, '/price-preview'), ['quantity' => '3'])->assertOk();
        $this->postJson($this->path($shop, $draft, '/images'), ['image' => UploadedFile::fake()->image('a.jpg', 100, 100)])->assertCreated();

        // no publication authority
        $this->publish($shop, $draft)->assertForbidden();
        $this->postJson($this->path($shop, $live, '/pause'))->assertForbidden();
        $this->postJson($this->path($shop, $live, '/archive'))->assertForbidden();
        $this->patchJson($this->path($shop, $live), ['unit_price' => '1'])->assertForbidden();
        $this->postJson($this->path($shop, $live, '/images'), ['image' => UploadedFile::fake()->image('b.jpg', 100, 100)])->assertForbidden();
        $this->deleteJson($this->path($shop, $live))->assertStatus(409);   // still not a draft; staff cannot archive instead

        // an archived listing cannot be restored by staff
        $this->signInAs($owner);
        $this->postJson($this->path($shop, $live, '/archive'))->assertOk();
        $this->signInAs($staff)->postJson($this->path($shop, $live, '/restore'))->assertForbidden();
        // and staff cannot publish a draft the manager later restores
        $this->assertSame('archived', MarketplaceListing::find($live)->status->value);
        $this->assertSame(0, MarketplaceListingEvent::where('actor_id', $staff->id)->whereIn('action', ['published', 'archived', 'restored', 'paused'])->count());
    }

    public function test_a_manager_publishes_and_the_owner_alone_manages_members(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $manager = $this->addMember($owner, $shop, 'manager', 'Manny');

        $this->signInAs($manager);
        $id = $this->createId($shop, $this->chicken());
        $this->publish($shop, $id)->assertOk();
        $this->patchJson($this->path($shop, $id), ['unit_price' => '8100'])->assertOk();
        $this->postJson($this->path($shop, $id, '/pause'))->assertOk();
        $this->postJson($this->path($shop, $id, '/archive'))->assertOk();
        $this->postJson($this->path($shop, $id, '/restore'))->assertOk();
        $this->getJson(self::SELLER."/shops/$shop/members")->assertForbidden();
    }

    public function test_non_members_and_other_shops_cannot_reach_a_shops_listings(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $id = $this->live($shop, $this->chicken());

        $stranger = $this->seller('Stranger');
        $mine = $this->activeShop($stranger);
        $this->signInAs($stranger);
        $this->getJson(self::SELLER."/shops/$shop/listings")->assertNotFound();
        $this->postJson(self::SELLER."/shops/$shop/listings", $this->chicken())->assertNotFound();
        $this->getJson($this->path($shop, $id))->assertNotFound();
        $this->patchJson($this->path($shop, $id), ['title' => 'Hijack'])->assertNotFound();
        $this->postJson($this->path($shop, $id, '/archive'))->assertNotFound();
        $this->deleteJson($this->path($shop, $id))->assertNotFound();
        $this->postJson($this->path($shop, $id, '/images'), ['image' => UploadedFile::fake()->image('a.jpg', 50, 50)])->assertNotFound();
        // my own shop id with someone else's listing id: still not found, never a hint it exists
        $this->getJson($this->path($mine, $id))->assertNotFound();
        $this->patchJson($this->path($mine, $id), ['title' => 'Hijack'])->assertNotFound();
        $this->postJson($this->path($mine, $id, '/pause'))->assertNotFound();
        $this->assertSame('Healthy point-of-lay chickens', MarketplaceListing::find($id)->title);

        $this->app['auth']->forgetGuards();
        $this->getJson(self::SELLER."/shops/$shop/listings")->assertUnauthorized();
        $unverified = User::factory()->unverified()->create();
        $this->signInAs($unverified)->getJson(self::SELLER."/shops/$shop/listings")->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    public function test_a_farm_role_alone_gives_no_access_to_shop_listings(): void
    {
        $shop = $this->activeShop($this->owner, ['farm_id' => $this->farm->id]);
        $this->live($shop, $this->chicken());
        $worker = $this->member(FarmRole::Manager);
        $this->signInAs($worker)->getJson(self::SELLER."/shops/$shop/listings")->assertNotFound();
    }

    public function test_the_listing_list_filters_by_status_kind_and_text(): void
    {
        $shop = $this->activeShop($this->seller());
        $this->live($shop, $this->chicken());
        $this->createId($shop, $this->yam());
        $this->createId($shop, $this->eggsCrate());

        $this->getJson(self::SELLER."/shops/$shop/listings")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson(self::SELLER."/shops/$shop/listings?status=draft")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::SELLER."/shops/$shop/listings?status=published")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SELLER."/shops/$shop/listings?product_kind=eggs")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SELLER."/shops/$shop/listings?q=yam")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SELLER."/shops/$shop/listings?status=nope")->assertStatus(422);
    }

    // ------------------------------------------------------------------ shop state

    public function test_suspending_a_shop_hides_its_listings_at_once_and_reinstating_restores_them(): void
    {
        $user = $this->seller();
        $shop = $this->activeShop($user);
        $id = $this->live($shop, $this->chicken());
        $other = $this->live($this->activeShop($this->seller('Other')), $this->yam());
        $slug = $this->slug($id);
        $this->assertContains($slug, $this->publicSlugs());

        $admin = $this->admin();
        $this->signInAs($admin)->postJson(self::ADMIN."/shops/$shop/suspend", ['reason' => 'Under investigation'])->assertOk();
        $this->assertNotContains($slug, $this->publicSlugs(), 'hidden immediately');
        $this->assertContains($this->slug($other), $this->publicSlugs());
        $this->getJson(self::PUBLIC."/$slug")->assertNotFound();
        $this->assertSame('published', MarketplaceListing::find($id)->status->value, 'the listing itself is untouched');

        $this->signInAs($user);
        $this->getJson($this->path($shop, $id))->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.is_public', false)->assertJsonPath('data.hidden_because', 'shop_not_active');
        $this->patchJson($this->path($shop, $id), ['unit_price' => '1'])->assertStatus(409)->assertJsonPath('code', 'shop_suspended');
        $this->postJson($this->path($shop, $id, '/pause'))->assertStatus(409)->assertJsonPath('code', 'shop_suspended');
        $this->postJson(self::SELLER."/shops/$shop/listings", $this->yam())->assertStatus(409)->assertJsonPath('code', 'shop_suspended');
        $this->getJson(self::SELLER."/shops/$shop/listings")->assertOk()->assertJsonCount(1, 'data');   // the seller can still read

        $this->signInAs($admin)->postJson(self::ADMIN."/shops/$shop/reinstate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertContains($slug, $this->publicSlugs(), 'reinstated shop: listings are back with no per-listing write');
    }

    // ------------------------------------------------------------------ platform moderation

    public function test_an_admin_restricts_and_lifts_with_reasons_history_and_audit(): void
    {
        $user = $this->seller();
        $shop = $this->activeShop($user);
        $id = $this->live($shop, $this->chicken());
        $slug = $this->slug($id);
        $admin = $this->admin();

        $this->signInAs($admin)->postJson(self::ADMIN."/listings/$id/restrict", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $r = $this->postJson(self::ADMIN."/listings/$id/restrict", ['reason' => 'Prohibited product'])->assertOk();
        $r->assertJsonPath('data.status', 'restricted')->assertJsonPath('data.is_public', false)->assertJsonPath('data.restriction.reason', 'Prohibited product')->assertJsonPath('data.restriction.by', $admin->id);
        $this->assertNotContains($slug, $this->publicSlugs());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.marketplace_listing_restricted', 'actor_id' => $admin->id, 'resource_id' => $id]);
        $this->assertDatabaseHas('marketplace_listing_events', ['listing_id' => $id, 'action' => 'restricted', 'actor_kind' => 'platform', 'actor_id' => $admin->id, 'reason' => 'Prohibited product', 'from_status' => 'published', 'to_status' => 'restricted']);
        // repeating is a no-op
        $this->postJson(self::ADMIN."/listings/$id/restrict", ['reason' => 'Prohibited product'])->assertOk();
        $this->assertSame(1, MarketplaceListingEvent::where('listing_id', $id)->where('action', 'restricted')->count());

        // the seller sees the reason and cannot edit, publish, pause or archive
        $this->signInAs($user);
        $this->getJson($this->path($shop, $id))->assertOk()->assertJsonPath('data.status', 'restricted')->assertJsonPath('data.restriction.reason', 'Prohibited product');
        $this->patchJson($this->path($shop, $id), ['title' => 'Try again'])->assertStatus(409)->assertJsonPath('code', 'listing_restricted');
        $this->publish($shop, $id)->assertStatus(409)->assertJsonPath('code', 'listing_restricted');
        $this->postJson($this->path($shop, $id, '/pause'))->assertStatus(409)->assertJsonPath('code', 'listing_restricted');
        $this->postJson($this->path($shop, $id, '/archive'))->assertStatus(409)->assertJsonPath('code', 'listing_restricted');
        $this->postJson($this->path($shop, $id, '/images'), ['image' => UploadedFile::fake()->image('a.jpg', 50, 50)])->assertStatus(409)->assertJsonPath('code', 'listing_restricted');

        // lifting does NOT republish
        $this->signInAs($admin)->postJson(self::ADMIN."/listings/$id/lift-restriction")->assertOk()->assertJsonPath('data.status', 'paused')->assertJsonPath('data.restriction.reason', null);
        $this->assertNotContains($slug, $this->publicSlugs());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.marketplace_listing_restriction_lifted', 'resource_id' => $id]);
        $this->signInAs($user);
        $this->publish($shop, $id)->assertOk();
        $this->assertContains($slug, $this->publicSlugs());

        $detail = $this->signInAs($admin)->getJson(self::ADMIN."/listings/$id")->assertOk()->json('data.history');
        $this->assertSame(['created', 'published', 'restricted', 'restriction_lifted', 'published'], array_column($detail, 'action'));
    }

    public function test_restriction_applies_to_drafts_and_paused_but_not_archived_and_lift_needs_a_restriction(): void
    {
        $shop = $this->activeShop($this->seller());
        $draft = $this->createId($shop, $this->yam());
        $paused = $this->live($shop, $this->chicken());
        $this->postJson($this->path($shop, $paused, '/pause'))->assertOk();
        $archived = $this->createId($shop, $this->eggsCrate());
        $this->postJson($this->path($shop, $archived, '/archive'))->assertOk();

        $this->signInAs($this->admin());
        $this->postJson(self::ADMIN."/listings/$draft/restrict", ['reason' => 'Blocked early'])->assertOk()->assertJsonPath('data.status', 'restricted');
        $this->postJson(self::ADMIN."/listings/$paused/restrict", ['reason' => 'Blocked'])->assertOk();
        $this->postJson(self::ADMIN."/listings/$archived/restrict", ['reason' => 'Blocked'])->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state');

        $live = $this->signInAs(User::findOrFail(MarketplaceListing::find($draft)->created_by))->postJson($this->path($shop, $this->createId($shop, $this->tomatoes()), '/publish'))->assertOk()->json('data.id');
        $this->signInAs($this->admin())->postJson(self::ADMIN."/listings/$live/lift-restriction")->assertStatus(409)->assertJsonPath('code', 'invalid_listing_state');
    }

    public function test_platform_listing_oversight_lists_filters_and_enforces_roles(): void
    {
        $shopA = $this->activeShop($this->seller('A'));
        $userB = $this->seller('B');
        $shopB = $this->activeShop($userB);
        $this->live($shopB, $this->eggsCrate());
        $a = $this->signInAs(User::findOrFail(MarketplaceShop::findOrFail($shopA)->created_by))->postJson(self::SELLER."/shops/$shopA/listings", $this->chicken())->json('data.id');
        $this->publish($shopA, $a)->assertOk();
        $this->createId($shopA, $this->yam());

        $this->signInAs($this->admin());
        $this->getJson(self::ADMIN.'/listings')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson(self::ADMIN.'/listings?status=draft')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ADMIN."/listings?shop_id=$shopB")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.shop.id', $shopB);
        $this->getJson(self::ADMIN.'/listings?product_kind=livestock')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ADMIN.'/listings?q=LST-')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson(self::ADMIN."/listings/$a")->assertOk()->assertJsonPath('data.shop.id', $shopA)->assertJsonPath('data.is_public', true);

        // support-role platform users may look but not decide; ordinary users are refused outright
        $viewer = $this->signInAs($this->admin(PlatformRole::Support));
        $viewer->getJson(self::ADMIN.'/listings')->assertOk();
        $viewer->postJson(self::ADMIN."/listings/$a/restrict", ['reason' => 'nope'])->assertForbidden();
        $this->assertSame('published', MarketplaceListing::find($a)->status->value);
        $this->signInAs($this->seller('Plain'))->getJson(self::ADMIN.'/listings')->assertForbidden();
        $this->postJson(self::ADMIN."/listings/$a/restrict", ['reason' => 'nope'])->assertForbidden();
        // there is no separate "hide" action: restricting is hiding
        $this->signInAs($this->admin())->postJson(self::ADMIN."/listings/$a/hide", ['reason' => 'x'])->assertStatus(404);
    }

    // ------------------------------------------------------------------ concurrency

    public function test_every_write_locks_the_shop_then_the_listing_row_in_that_order(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->createId($shop, $this->chicken());

        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            if (preg_match('/for update/i', $q->sql) && preg_match('/from `(marketplace_\w+)`/i', $q->sql, $m)) {
                $seen[] = $m[1];
            }
        });
        $locks = function (callable $call) use (&$seen): array {
            $seen = [];
            $call();

            return $seen;
        };

        foreach ([
            'publish' => fn () => $this->publish($shop, $id)->assertOk(),
            'update' => fn () => $this->patchJson($this->path($shop, $id), ['unit_price' => '8100'])->assertOk(),
            'pause' => fn () => $this->postJson($this->path($shop, $id, '/pause'))->assertOk(),
            'archive' => fn () => $this->postJson($this->path($shop, $id, '/archive'))->assertOk(),
            'restore' => fn () => $this->postJson($this->path($shop, $id, '/restore'))->assertOk(),
            'image' => fn () => $this->postJson($this->path($shop, $id, '/images'), ['image' => UploadedFile::fake()->image('a.jpg', 40, 40)])->assertCreated(),
        ] as $name => $call) {
            $this->assertSame(['marketplace_shops', 'marketplace_listings'], array_slice($locks($call), 0, 2), "$name must lock the shop row, then the listing row");
        }

        // the platform path locks only the listing and writes history + audit in the same transaction
        $seen = $locks(fn () => $this->signInAs($this->admin())->postJson(self::ADMIN."/listings/$id/restrict", ['reason' => 'Check'])->assertOk());
        $this->assertSame('marketplace_listings', $seen[0]);
    }

    public function test_references_and_slugs_stay_unique_across_many_listings_with_the_same_title(): void
    {
        $shop = $this->activeShop($this->seller());
        $ids = array_map(fn () => $this->createId($shop, $this->chicken(['title' => 'Same title'])), range(1, 6));
        $rows = MarketplaceListing::whereIn('id', $ids)->orderBy('reference')->get();
        $this->assertCount(6, $rows->pluck('reference')->unique());
        $this->assertCount(6, $rows->pluck('slug')->unique());
        $year = now()->format('Y');
        $this->assertSame(array_map(fn ($n) => "LST-$year-0000$n", range(1, 6)), $rows->pluck('reference')->all());
        // a deleted draft's reference and slug are never reused
        $this->deleteJson($this->path($shop, $ids[5]))->assertOk();
        $next = MarketplaceListing::findOrFail($this->createId($shop, $this->chicken(['title' => 'Same title'])));
        $this->assertSame("LST-$year-00007", $next->reference);
    }

    public function test_a_write_racing_a_deletion_finds_nothing_and_changes_nothing(): void
    {
        $shop = $this->activeShop($this->seller());
        $id = $this->createId($shop, $this->chicken());
        MarketplaceListing::whereKey($id)->delete();   // another request already deleted the draft
        $this->patchJson($this->path($shop, $id), ['unit_price' => '1'])->assertNotFound();
        $this->publish($shop, $id)->assertNotFound();
        $this->signInAs($this->admin())->getJson(self::ADMIN."/listings/$id")->assertOk()->assertJsonPath('data.deleted_at', fn ($v) => $v !== null);
    }
}
