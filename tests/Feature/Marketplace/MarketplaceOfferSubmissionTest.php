<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceShopMember;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceOfferSubmissionTest extends OfferTestCase
{
    public function test_a_buyer_can_make_an_offer_that_snapshots_the_listing_and_expires_in_48_hours(): void
    {
        $this->offer(['quantity' => '10', 'unit_price' => '7500.50'])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempt_no', 1)
            ->assertJsonPath('data.terms.unit_price', '7500.50')
            ->assertJsonPath('data.terms.quantity', '10')
            ->assertJsonPath('data.terms.total', '75005.00')
            ->assertJsonPath('data.listing_snapshot.listed_unit_price', '8000.00')
            ->assertJsonPath('data.listing_snapshot.unit', 'head')
            ->assertJsonPath('data.agreement', 'none')
            ->assertJsonPath('data.contact', null)
            ->assertJsonPath('data.history.0.action', 'submitted');

        $offer = MarketplaceOffer::firstOrFail();
        $this->assertMatchesRegularExpression('/^OFR-\d{4}-\d{5}$/', $offer->reference);
        $this->assertSame('P', $offer->pending_slot);
        $this->assertEqualsWithDelta(48 * 3600, now()->diffInSeconds($offer->expires_at, false), 5);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_submitted')->count());
    }

    public function test_the_expiry_window_attempt_limit_and_floor_come_from_platform_settings(): void
    {
        $this->setting('marketplace_offer_expiry_hours', 6);
        $this->setting('marketplace_max_offers_per_buyer', 1);
        $this->setting('marketplace_min_offer_percent', 90);

        $this->offerStatus()->assertOk()->assertJsonPath('data.rules.max_attempts', 1)->assertJsonPath('data.rules.min_offer_percent', '90')
            ->assertJsonPath('data.rules.minimum_unit_price', '7200.00')->assertJsonPath('data.rules.offer_expiry_hours', 6);
        $this->offer(['unit_price' => '7199.99'])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $id = $this->offerId(['unit_price' => '7200']);
        $this->assertEqualsWithDelta(6 * 3600, now()->diffInSeconds($this->stored($id)->expires_at, false), 5);
        $this->respond('reject', $id)->assertOk();
        $this->offer(['unit_price' => '7500'])->assertStatus(409)->assertJsonPath('code', 'offer_limit_reached');
    }

    public function test_the_price_floor_is_exact_and_checked_before_an_attempt_is_used(): void
    {
        $this->editListing(['unit_price' => '3333.33'])->assertOk();
        // 70% of 3333.33 = 2333.331 -> the lowest whole-kobo price is 2333.34
        $this->offerStatus()->assertJsonPath('data.rules.minimum_unit_price', '2333.34');
        $this->offer(['unit_price' => '2333.33'])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->offer(['unit_price' => '0'])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->offer(['unit_price' => '3333.33'])->assertStatus(422)->assertJsonValidationErrors('unit_price');   // equal to the listed price
        $this->offer(['unit_price' => '4000'])->assertStatus(422)->assertJsonValidationErrors('unit_price');      // above it
        $this->offer(['unit_price' => '12.345'])->assertStatus(422)->assertJsonValidationErrors('unit_price');    // not naira and kobo
        $this->assertSame(0, MarketplaceOffer::count());
        $this->offerStatus()->assertJsonPath('data.rules.attempts_used', 0);

        $this->offer(['unit_price' => '2333.34'])->assertCreated();   // exactly the floor
        $this->assertSame(1, MarketplaceOffer::count());
    }

    public function test_a_bad_quantity_is_rejected_without_using_an_attempt(): void
    {
        $this->offer(['quantity' => '4'])->assertStatus(422)->assertJsonValidationErrors('quantity');      // below the minimum order of 5
        $this->offer(['quantity' => '121'])->assertStatus(422)->assertJsonValidationErrors('quantity');    // above the declared available 120
        $this->offer(['quantity' => '10.5'])->assertStatus(422)->assertJsonValidationErrors('quantity');   // head is a whole-number unit
        $this->offer(['quantity' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->assertSame(0, MarketplaceOffer::count());
    }

    public function test_offers_are_only_possible_on_public_negotiable_listings_by_outsiders(): void
    {
        $fixed = $this->live($this->shopId, $this->chicken(['title' => 'Fixed price hens', 'negotiable' => false]));
        $this->offer([], null, $this->slug($fixed))->assertStatus(409)->assertJsonPath('code', 'listing_not_negotiable');

        $this->signInAs($this->sellerUser);
        $draft = $this->createId($this->shopId, $this->chicken(['title' => 'Draft birds']));
        $this->offer([], null, $this->slug($draft))->assertNotFound();

        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/pause")->assertOk();
        $this->offer()->assertNotFound();
        $this->offerStatus()->assertNotFound();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/publish")->assertOk();

        $this->offer([], null, 'no-such-listing')->assertNotFound();
        $this->assertSame(0, MarketplaceOffer::count());

        $this->app['auth']->forgetGuards();
        $this->postJson($this->offerUrl(), ['quantity' => '10', 'unit_price' => '7000'])->assertUnauthorized();
    }

    public function test_shop_members_and_the_linked_farm_owner_cannot_negotiate_with_themselves(): void
    {
        foreach (['owner' => $this->sellerUser, 'manager' => $this->addMember($this->sellerUser, $this->shopId, 'manager'), 'staff' => $this->addMember($this->sellerUser, $this->shopId, 'staff')] as $who) {
            $this->offer([], $who)->assertForbidden()->assertJsonPath('code', 'cannot_negotiate_own_listing');
        }
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/listings/{$this->slug}/purchase-intent", ['quantity' => '10'])->assertForbidden();

        // a farm-backed shop: the farm's owner is blocked even if they are not in the shop
        $farmShop = $this->activeShop($this->owner, ['farm_id' => $this->farm->id, 'seller_type' => 'farm', 'name' => 'Green Acres Shop']);
        $listing = $this->live($farmShop, $this->chicken(['title' => 'Farm chickens']));
        MarketplaceShopMember::where('shop_id', $farmShop)->where('user_id', $this->owner->id)->delete();   // even outside the shop's member list, the farm's owner is blocked
        $this->offer([], $this->owner, $this->slug($listing))->assertForbidden()->assertJsonPath('code', 'cannot_negotiate_own_listing');
        $this->offer([], User::factory()->create(), $this->slug($listing))->assertCreated();   // an unrelated user can
        $this->assertSame(1, MarketplaceOffer::count());
    }

    public function test_one_open_offer_at_a_time_and_a_limit_of_three_that_counts_rejections_and_expiries(): void
    {
        $first = $this->offerId();
        $this->offer()->assertStatus(409)->assertJsonPath('code', 'offer_pending')->assertJsonPath('details.offer_id', $first);

        $this->respond('reject', $first)->assertOk();
        $second = $this->offerId(['unit_price' => '7100']);
        $this->assertSame(2, $this->stored($second)->attempt_no);

        Carbon::setTestNow(now()->addHours(49));   // the second lapses: it still uses an attempt
        $this->offerStatus()->assertJsonPath('data.rules.attempts_used', 2)->assertJsonPath('data.can_offer', true);
        $this->offerId(['unit_price' => '7200']);   // writing settles the lapsed offer and frees the slot
        $this->assertSame('expired', $this->stored($second)->status->value);

        $this->offerStatus()->assertJsonPath('data.rules.attempts_used', 3)->assertJsonPath('data.blocked_reason', 'offer_pending');
        Carbon::setTestNow(now()->addHours(49));
        $this->offer(['unit_price' => '7500'])->assertStatus(409)->assertJsonPath('code', 'offer_limit_reached')
            ->assertJsonPath('details.max_attempts', 3)->assertJsonPath('details.attempts_used', 3);
        $this->offerStatus()->assertJsonPath('data.can_offer', false)->assertJsonPath('data.blocked_reason', 'offer_limit_reached')->assertJsonPath('data.rules.attempts_remaining', 0);
    }

    public function test_after_the_seller_accepts_the_buyer_cannot_offer_again_but_other_buyers_can(): void
    {
        $id = $this->offerId();
        $this->respond('accept', $id)->assertOk();
        $this->offer()->assertStatus(409)->assertJsonPath('code', 'offer_already_accepted');
        $this->offer([], User::factory()->create())->assertCreated();
    }

    public function test_buyers_see_only_their_own_offers_and_enquiries(): void
    {
        $mine = $this->offerId();
        $other = User::factory()->create();
        $this->signInAs($other)->getJson(self::SELLER."/my/offers/$mine")->assertNotFound();
        $this->getJson(self::SELLER.'/my/enquiries')->assertOk()->assertJsonCount(0, 'data');
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$mine")->assertOk()->assertJsonPath('data.id', $mine);
        $this->getJson(self::SELLER.'/my/enquiries')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'offer');
    }

    public function test_the_database_itself_refuses_a_second_pending_offer_and_a_repeated_attempt(): void
    {
        $id = $this->offerId();
        $row = MarketplaceOffer::findOrFail($id)->getAttributes();
        unset($row['id']);

        $twin = $row;
        $twin['reference'] = 'OFR-9999-00001';
        $twin['attempt_no'] = 2;   // a new attempt number, but still pending for the same buyer and listing
        try {
            DB::table('marketplace_offers')->insert(['id' => (string) Str::uuid7()] + $twin);
            $this->fail('A second pending offer must be impossible.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('offers_one_pending_per_buyer', $e->getMessage());
        }

        $repeat = $row;
        $repeat['reference'] = 'OFR-9999-00002';
        $repeat['status'] = 'rejected';
        $repeat['pending_slot'] = null;   // settled, but attempt 1 again
        try {
            DB::table('marketplace_offers')->insert(['id' => (string) Str::uuid7()] + $repeat);
            $this->fail('An attempt number can be used once.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('offers_attempt_unique', $e->getMessage());
        }
    }

    public function test_the_floor_setting_is_a_validated_platform_setting(): void
    {
        $this->signInAs($this->admin());
        $put = fn ($key, $value) => $this->putJson("/api/v1/platform-admin/settings/$key", ['value' => $value]);
        $put('marketplace_min_offer_percent', 72.5)->assertOk()->assertJsonPath('data.value', 72.5);
        foreach ([0, 100, 150, 70.555, 'abc'] as $bad) {
            $put('marketplace_min_offer_percent', $bad)->assertStatus(422);
        }
        $put('marketplace_offer_expiry_hours', 0)->assertStatus(422);
        $put('marketplace_offer_expiry_hours', 721)->assertStatus(422);
        $put('marketplace_max_offers_per_buyer', 11)->assertStatus(422);
        $put('marketplace_max_offers_per_buyer', 2)->assertOk();

        $this->offerStatus()->assertJsonPath('data.rules.min_offer_percent', '72.5')->assertJsonPath('data.rules.minimum_unit_price', '5800.00')->assertJsonPath('data.rules.max_attempts', 2);
    }
}
