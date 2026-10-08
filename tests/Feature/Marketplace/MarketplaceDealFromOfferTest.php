<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealEvent;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Door A: the seller accepted a negotiated offer (Phase 24) and the BUYER turns it into a deal. */
class MarketplaceDealFromOfferTest extends DealTestCase
{
    public function test_the_buyer_confirms_an_accepted_offer_and_the_deal_keeps_its_exact_terms(): void
    {
        $offer = $this->acceptedOfferId();   // 10 head at 7000 against a listed 8000

        $this->confirmOffer($offer, ['contact_phone' => self::BUYER_PHONE])->assertCreated()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.terms_source', 'accepted_offer')
            ->assertJsonPath('data.source.kind', 'offer')->assertJsonPath('data.source.id', $offer)
            ->assertJsonPath('data.terms.quantity', '10')->assertJsonPath('data.terms.unit', 'head')
            ->assertJsonPath('data.terms.unit_price', '7000.00')->assertJsonPath('data.terms.product_total', '70000.00')
            ->assertJsonPath('data.terms.listed_unit_price', '8000.00')->assertJsonPath('data.terms.price_basis', 'negotiated')
            ->assertJsonPath('data.terms.total_covers', 'product_only')->assertJsonPath('data.terms.frozen', true)
            ->assertJsonPath('data.fulfilment.method', 'pickup')->assertJsonPath('data.fulfilment.delivery_charge.mode', 'not_applicable')
            ->assertJsonPath('data.completion.verification', 'self_reported')->assertJsonPath('data.completion.state', 'awaiting_both')
            ->assertJsonPath('data.contact', null)->assertJsonPath('data.contact_available', true)
            ->assertJsonPath('data.can.complete', true)->assertJsonPath('data.can.cancel', true);

        $deal = MarketplaceDeal::firstOrFail();
        $this->assertMatchesRegularExpression('/^DEL-\d{4}-\d{5}$/', $deal->reference);
        $this->assertSame($offer, $deal->offer_id);
        $this->assertNull($deal->confirmation_id);
        $this->assertSame($this->buyer->id, $deal->buyer_id);
        $this->assertSame($this->sellerUser->id, $deal->seller_confirmed_by);
        $this->assertSame(self::BUYER_PHONE, $deal->buyer_contact_phone);
        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $deal->id)->where('action', 'created')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_created')->count());
    }

    public function test_confirming_twice_returns_the_same_deal_and_never_a_second_one(): void
    {
        $offer = $this->acceptedOfferId();
        $first = $this->confirmOffer($offer)->assertCreated()->json('data.id');

        $this->confirmOffer($offer)->assertOk()->assertJsonPath('data.id', $first);
        $this->confirmOffer($offer, ['contact_phone' => self::BUYER_PHONE])->assertOk()->assertJsonPath('data.id', $first);

        $this->assertSame(1, MarketplaceDeal::count());
        $this->assertNull($this->deal($first)->buyer_contact_phone);   // a repeat changes nothing
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_created')->count());
        $this->assertSame(1, MarketplaceDealEvent::count());
    }

    public function test_later_listing_edits_never_change_a_confirmed_deal(): void
    {
        $id = $this->dealId();
        $before = $this->deal($id)->only(['quantity', 'unit_price', 'product_total', 'product_name', 'unit_code', 'listing_title', 'fulfilment_method']);

        $version = (int) MarketplaceListing::findOrFail($this->listingId)->version;
        $this->editListing(['unit_price' => '9500', 'title' => 'Premium chickens', 'available_quantity' => '60', 'version' => $version])->assertOk();

        $this->assertSame($before, $this->deal($id)->only(['quantity', 'unit_price', 'product_total', 'product_name', 'unit_code', 'listing_title', 'fulfilment_method']));
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deals/$id")->assertOk()
            ->assertJsonPath('data.terms.unit_price', '7000.00')->assertJsonPath('data.terms.product_total', '70000.00')->assertJsonPath('data.listing.title', 'Healthy point-of-lay chickens');
    }

    public function test_only_the_buyer_who_made_the_offer_can_confirm_it_and_the_seller_has_no_way_to(): void
    {
        $offer = $this->acceptedOfferId();
        $stranger = User::factory()->create();

        $this->confirmOffer($offer, [], $stranger)->assertNotFound();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/my/offers/$offer/deal")->assertNotFound();   // not their offer
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/offers/$offer/deal")->assertStatus(404);   // there is no seller-side door
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_an_offer_that_is_not_accepted_cannot_become_a_deal(): void
    {
        $pending = $this->offerId();
        $this->confirmOffer($pending)->assertStatus(409)->assertJsonPath('code', 'offer_not_accepted')->assertJsonPath('details.status', 'pending');

        $other = User::factory()->create();
        $rejected = $this->offerId([], $other);
        $this->respond('reject', $rejected)->assertOk();
        $this->confirmOffer($rejected, [], $other)->assertStatus(409)->assertJsonPath('code', 'offer_not_accepted');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_buyer_must_confirm_within_the_configured_window(): void
    {
        $offer = $this->acceptedOfferId();
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$offer")->assertOk()
            ->assertJsonPath('data.deal_confirmation.open', true)->assertJsonPath('data.deal_confirmation.window_hours', 72)->assertJsonPath('data.deal', null);

        Carbon::setTestNow(now()->addHours(71));
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$offer")->assertOk()->assertJsonPath('data.deal_confirmation.open', true);
        Carbon::setTestNow(now()->addHours(2));   // 73h after acceptance
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$offer")->assertOk()->assertJsonPath('data.deal_confirmation.open', false);
        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'deal_window_closed');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_window_is_a_platform_setting(): void
    {
        $this->setting('marketplace_deal_confirmation_hours', 6);
        $offer = $this->acceptedOfferId();
        Carbon::setTestNow(now()->addHours(7));
        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'deal_window_closed');

        $this->setting('marketplace_deal_confirmation_hours', 100);   // raised again: the deadline is derived, so the same offer is open once more
        $this->confirmOffer($offer)->assertCreated();
    }

    public function test_a_repeat_after_the_window_still_returns_the_existing_deal(): void
    {
        $offer = $this->acceptedOfferId();
        $id = $this->confirmOffer($offer)->assertCreated()->json('data.id');
        Carbon::setTestNow(now()->addDays(10));

        $this->confirmOffer($offer)->assertOk()->assertJsonPath('data.id', $id);
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$offer")->assertOk()->assertJsonPath('data.deal.id', $id);
    }

    public function test_availability_and_listing_state_are_checked_before_a_deal_exists(): void
    {
        $offer = $this->acceptedOfferId();   // 10 head accepted

        // the seller lowers the declared stock below the accepted quantity (accepted offers keep their snapshot, so the edit is allowed)
        $version = (int) MarketplaceListing::findOrFail($this->listingId)->version;
        $this->editListing(['available_quantity' => '8', 'min_order_quantity' => '2', 'version' => $version])->assertOk();
        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'quantity_unavailable')
            ->assertJsonPath('details.declared_available', '8')->assertJsonPath('details.basis', 'seller_declared');

        $version = (int) MarketplaceListing::findOrFail($this->listingId)->version;
        $this->editListing(['available_quantity' => '50', 'version' => $version])->assertOk();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/pause")->assertOk();
        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'listing_unavailable');

        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/publish")->assertOk();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/{$this->shopId}/suspend", ['reason' => 'Under review'])->assertOk();
        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'shop_not_active');

        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/{$this->shopId}/reinstate")->assertOk();
        $this->confirmOffer($offer)->assertCreated();   // nothing was spent by the refusals
        $this->assertSame(1, MarketplaceDeal::count());
    }

    public function test_a_listing_that_now_sells_something_else_cannot_back_the_deal(): void
    {
        $offer = $this->acceptedOfferId();
        MarketplaceListing::whereKey($this->listingId)->update(['custom_product_name' => 'Guinea fowl']);   // product identity moved underneath the agreement

        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'deal_terms_stale');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_fulfilment_method_follows_what_the_listing_offers(): void
    {
        // pickup-only listing: implied
        $this->confirmOffer($this->acceptedOfferId())->assertCreated()->assertJsonPath('data.fulfilment.method', 'pickup');

        // a listing offering both makes the buyer choose
        $buyer = User::factory()->create(['name' => 'Chooser']);
        $this->signInAs($this->sellerUser);
        $both = $this->live($this->shopId, $this->chicken(['title' => 'Delivered chickens', 'negotiable' => true, 'fulfilment' => 'both', 'delivery_coverage' => ['Oyo'], 'dispatch_estimate' => 'same_day', 'delivery_charge' => 'agreed_separately']));
        $slug = $this->slug($both);
        $this->signInAs($buyer);
        $offer = $this->postJson($this->offerUrl($slug), ['quantity' => '10', 'unit_price' => '7000'])->assertCreated()->json('data.id');
        $this->respond('accept', $offer)->assertOk();

        $this->confirmOffer($offer, [], $buyer)->assertStatus(422)->assertJsonValidationErrors('fulfilment_method');
        $this->confirmOffer($offer, ['fulfilment_method' => 'courier'], $buyer)->assertStatus(422)->assertJsonValidationErrors('fulfilment_method');
        $this->confirmOffer($offer, ['fulfilment_method' => 'seller_delivery'], $buyer)->assertCreated()
            ->assertJsonPath('data.fulfilment.method', 'seller_delivery')->assertJsonPath('data.fulfilment.listing_offered', 'both')
            ->assertJsonPath('data.fulfilment.delivery_coverage', ['Oyo'])->assertJsonPath('data.fulfilment.dispatch_estimate.value', 'same_day')
            ->assertJsonPath('data.fulfilment.delivery_charge.mode', 'agreed_separately')->assertJsonPath('data.fulfilment.delivery_charge.amount', null)
            ->assertJsonPath('data.fulfilment.delivery_charge.display', 'To be agreed directly')->assertJsonPath('data.fulfilment.delivery_charge.included_in_product_total', false)
            ->assertJsonPath('data.terms.product_total', '70000.00');   // the unknown charge is not in the total
    }

    public function test_the_buyer_phone_is_optional_and_validated(): void
    {
        $offer = $this->acceptedOfferId();
        foreach (['abc', '12345', '+234 803 123 4567', '080-1234567', '+2348031234567890123'] as $bad) {
            $this->confirmOffer($offer, ['contact_phone' => $bad])->assertStatus(422)->assertJsonValidationErrors('contact_phone');
        }
        $this->assertSame(0, MarketplaceDeal::count());
        $id = $this->confirmOffer($offer, ['contact_phone' => null])->assertCreated()->json('data.id');
        $this->assertNull($this->deal($id)->buyer_contact_phone);
    }

    public function test_a_seller_without_any_contact_channel_cannot_be_reached_so_no_deal_forms(): void
    {
        $offer = $this->acceptedOfferId();
        MarketplaceShop::whereKey($this->shopId)->update(['contact_phone' => null, 'contact_whatsapp' => null, 'contact_email' => null]);

        $this->confirmOffer($offer)->assertStatus(409)->assertJsonPath('code', 'seller_contact_unavailable');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_offer_shows_the_deal_and_what_to_do_next(): void
    {
        $offer = $this->acceptedOfferId();
        $this->signInAs($this->sellerUser)->getJson(self::SELLER."/shops/{$this->shopId}/offers/$offer")->assertOk()
            ->assertJsonPath('data.deal_confirmation.required_from', 'buyer')->assertJsonPath('data.contact', null);
        $deal = $this->confirmOffer($offer)->assertCreated()->json('data.id');

        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$offer")->assertOk()->assertJsonPath('data.deal.id', $deal)->assertJsonPath('data.contact', null);
        $this->signInAs($this->buyer)->getJson(self::SELLER.'/my/enquiries')->assertOk()->assertJsonPath('data.0.deal.reference', $this->deal($deal)->reference);
    }
}
