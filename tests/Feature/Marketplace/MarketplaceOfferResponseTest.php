<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceOfferEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class MarketplaceOfferResponseTest extends OfferTestCase
{
    public function test_an_owner_or_manager_can_accept_and_reject_but_staff_can_only_view(): void
    {
        $manager = $this->addMember($this->sellerUser, $this->shopId, 'manager');
        $staff = $this->addMember($this->sellerUser, $this->shopId, 'staff');
        $a = $this->offerId();
        $b = $this->offerId([], User::factory()->create());

        $this->respond('accept', $a, $staff)->assertForbidden();
        $this->respond('reject', $a, $staff)->assertForbidden();
        $this->getJson(self::SELLER."/shops/{$this->shopId}/offers")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson(self::SELLER."/shops/{$this->shopId}/offers/$a")->assertOk();
        $this->getJson(self::SELLER."/shops/{$this->shopId}/purchase-intents")->assertOk();

        $this->respond('accept', $a, $manager)->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.agreement', 'seller_accepted');
        $this->respond('reject', $b)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame($manager->id, $this->stored($a)->responded_by);
        $this->assertNull($this->stored($a)->pending_slot);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_accepted')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_rejected')->count());
    }

    public function test_offers_are_invisible_outside_their_shop(): void
    {
        $id = $this->offerId();
        $otherSeller = $this->seller('Other Seller');
        $otherShop = $this->activeShop($otherSeller);

        $this->respond('accept', $id, $otherSeller, $otherShop)->assertNotFound();           // another shop's offer
        $this->respond('accept', $id, $otherSeller, $this->shopId)->assertNotFound();        // not a member of this shop
        $this->getJson(self::SELLER."/shops/$otherShop/offers/$id")->assertNotFound();
        $this->getJson(self::SELLER."/shops/$otherShop/offers")->assertOk()->assertJsonCount(0, 'data');
        $this->respond('accept', $id, $this->buyer)->assertNotFound();                      // the buyer is no member
        $this->assertSame('pending', $this->stored($id)->status->value);
    }

    public function test_a_repeated_decision_is_idempotent_and_a_conflicting_one_is_refused(): void
    {
        $id = $this->offerId();
        $this->respond('accept', $id)->assertOk();
        $this->respond('accept', $id)->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(2, MarketplaceOfferEvent::where('offer_id', $id)->count());   // submitted + accepted, nothing duplicated
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_accepted')->count());
        $this->respond('reject', $id)->assertStatus(409)->assertJsonPath('code', 'offer_not_pending');
        $this->assertSame('accepted', $this->stored($id)->status->value);
    }

    public function test_a_lapsed_offer_cannot_be_answered_and_the_lapse_is_recorded(): void
    {
        $id = $this->offerId();
        Carbon::setTestNow(now()->addHours(49));
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$id")->assertOk()->assertJsonPath('data.status', 'expired');   // read time
        $this->assertSame('pending', $this->stored($id)->status->value);                                                         // not yet persisted

        $this->respond('accept', $id)->assertStatus(409)->assertJsonPath('code', 'offer_expired');
        $this->assertSame('expired', $this->stored($id)->status->value);                                                          // write time persists it
        $this->assertNull($this->stored($id)->pending_slot);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_expired')->count());
        $this->respond('reject', $id)->assertStatus(409)->assertJsonPath('code', 'offer_expired');
    }

    public function test_the_expiry_command_persists_only_lapsed_offers_and_is_repeatable(): void
    {
        $lapsing = $this->offerId();
        $this->setting('marketplace_offer_expiry_hours', 100);
        $later = $this->offerId([], User::factory()->create());
        Carbon::setTestNow(now()->addHours(60));
        $this->setting('marketplace_offer_expiry_hours', 48);

        // the first offer (48h) has lapsed, the second (100h) has not
        Artisan::call('marketplace:expire-offers');
        Artisan::call('marketplace:expire-offers');
        $this->signInAs($this->sellerUser);
        $this->assertSame('expired', $this->stored($lapsing)->status->value);
        $this->assertSame('pending', $this->stored($later)->status->value);
        $this->assertSame(1, MarketplaceOfferEvent::where('action', 'expired')->count());
        $this->getJson(self::SELLER."/shops/{$this->shopId}/offers?status=expired")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_paused_listing_cannot_have_offers_accepted_but_they_can_be_rejected(): void
    {
        $a = $this->offerId();
        $b = $this->offerId([], User::factory()->create());
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/pause")->assertOk();

        $this->respond('accept', $a)->assertStatus(409)->assertJsonPath('code', 'listing_unavailable');
        $this->assertSame('pending', $this->stored($a)->status->value);   // nothing mutated
        $this->respond('reject', $b)->assertOk();

        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/publish")->assertOk();
        $this->respond('accept', $a)->assertOk();
    }

    public function test_a_suspended_shop_cannot_accept(): void
    {
        $id = $this->offerId();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/{$this->shopId}/suspend", ['reason' => 'Under review'])->assertOk();
        $this->respond('accept', $id)->assertStatus(409)->assertJsonPath('code', 'shop_not_active');
        $this->assertSame('pending', $this->stored($id)->status->value);
    }

    public function test_a_material_listing_change_voids_pending_offers_and_refunds_the_attempt(): void
    {
        $cases = [
            'price' => ['unit_price' => '8500'],
            'available below the offer' => ['available_quantity' => '8', 'min_order_quantity' => '2'],
            'minimum order above the offer' => ['min_order_quantity' => '20'],
            'no longer negotiable' => ['negotiable' => false],
        ];
        foreach ($cases as $label => $edit) {
            $this->setting('marketplace_max_offers_per_buyer', 1);   // one attempt: a voided offer must not use it up
            $buyer = User::factory()->create();
            $id = $this->offerId(['quantity' => '10'], $buyer);
            $this->editListing($edit)->assertOk();
            $this->assertSame('voided', $this->stored($id)->status->value, $label);
            $this->assertSame('listing_changed', $this->stored($id)->void_reason, $label);
            $this->assertNull($this->stored($id)->pending_slot, $label);
            $this->respond('accept', $id)->assertStatus(409)->assertJsonPath('code', 'offer_voided');
            $this->signInAs($buyer)->getJson(self::SELLER."/my/offers/$id")->assertJsonPath('data.status', 'voided')->assertJsonPath('data.void_reason', 'listing_changed');

            // restore the listing for the next case
            $this->editListing(['unit_price' => '8000', 'available_quantity' => '120', 'min_order_quantity' => '5', 'negotiable' => true])->assertOk();
            $this->offerStatus($buyer)->assertJsonPath('data.rules.attempts_used', 0)->assertJsonPath('data.can_offer', true);
        }
    }

    public function test_voiding_keeps_history_and_audit_and_does_not_count_as_an_attempt(): void
    {
        $id = $this->offerId();
        $this->editListing(['unit_price' => '9000'])->assertOk();
        $this->assertSame(['submitted', 'voided'], MarketplaceOfferEvent::where('offer_id', $id)->orderBy('created_at')->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_voided')->count());
        $this->offerStatus()->assertJsonPath('data.rules.attempts_used', 0)->assertJsonPath('data.can_offer', true);
        // the new price is the reference now: 70% of 9000
        $this->offer(['unit_price' => '6299.99'])->assertStatus(422);
        $this->offer(['unit_price' => '6300'])->assertCreated();
    }

    public function test_changes_that_do_not_alter_the_offer_leave_it_pending(): void
    {
        $id = $this->offerId(['quantity' => '10']);
        $this->editListing(['title' => 'Healthy chickens, vaccinated', 'description' => 'New text', 'available_quantity' => '60', 'min_order_quantity' => '10'])->assertOk();
        $this->assertSame('pending', $this->stored($id)->status->value);
        $this->respond('accept', $id)->assertOk();
    }

    public function test_an_accepted_offer_is_a_preserved_snapshot_when_the_listing_changes_later(): void
    {
        $id = $this->offerId(['quantity' => '10', 'unit_price' => '7000']);
        $this->respond('accept', $id)->assertOk();
        $this->editListing(['unit_price' => '12000', 'available_quantity' => '5', 'min_order_quantity' => '1', 'negotiable' => false])->assertOk();

        $this->assertSame('accepted', $this->stored($id)->status->value);
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$id")->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.terms.unit_price', '7000.00')->assertJsonPath('data.terms.total', '70000.00')
            ->assertJsonPath('data.listing_snapshot.listed_unit_price', '8000.00')->assertJsonPath('data.listing_snapshot.available_quantity', '120');
    }

    public function test_contact_details_are_never_exposed_even_after_acceptance(): void
    {
        $id = $this->offerId();
        $this->respond('accept', $id)->assertOk();
        $seller = $this->getJson(self::SELLER."/shops/{$this->shopId}/offers/$id")->assertOk()->assertJsonPath('data.buyer.name', 'Bola Buyer');
        $buyer = $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$id")->assertOk()->assertJsonPath('data.contact', null);

        $json = $seller->getContent().$buyer->getContent();
        foreach ([$this->buyer->email, $this->sellerUser->email, self::PHONE, '+2348099998888', 'ada.private@example.com', '12 Secret Street', $this->buyer->id] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_shop_can_filter_its_offers(): void
    {
        $a = $this->offerId();
        $this->offerId([], User::factory()->create());
        $this->respond('reject', $a)->assertOk();
        $this->getJson(self::SELLER."/shops/{$this->shopId}/offers?status=rejected")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SELLER."/shops/{$this->shopId}/offers?status=pending")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::SELLER."/shops/{$this->shopId}/offers?status=bogus")->assertStatus(422);
    }
}
