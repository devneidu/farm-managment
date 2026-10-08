<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/** Door B: a fixed-price purchase request. The SELLER confirms first (no agreement, no contact), then the BUYER confirms the exact terms. */
class MarketplaceDealFromIntentTest extends DealTestCase
{
    public function test_a_seller_confirmation_is_not_an_agreement_and_exposes_no_contact(): void
    {
        $intent = $this->intentId('10');
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_buyer')->assertJsonPath('data.agreement', 'none')->assertJsonPath('data.contact', null)
            ->assertJsonPath('data.terms.unit_price', '3500.00')->assertJsonPath('data.terms.product_total', '35000.00')->assertJsonPath('data.terms.quantity', '10')
            ->assertJsonPath('data.fulfilment.method', 'pickup')->assertJsonPath('data.withdrawable', true);

        $this->assertSame(0, MarketplaceDeal::count());   // an unconfirmed intent is never an agreement
        $this->assertSame(1, MarketplaceDealConfirmation::count());
        $this->assertMatchesRegularExpression('/^CNF-\d{4}-\d{5}$/', MarketplaceDealConfirmation::firstOrFail()->reference);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_confirmation_created')->count());

        // nothing about contact is offered to the buyer yet, and there is no deal to ask about
        $this->signInAs($this->buyer)->getJson(self::SELLER.'/my/deals')->assertOk()->assertJsonCount(0, 'data');
        $this->signInAs($this->buyer)->getJson(self::SELLER.'/my/enquiries')->assertOk()
            ->assertJsonPath('data.0.deal_flow', 'awaiting_buyer')->assertJsonPath('data.0.confirmation.confirmable', true)->assertJsonPath('data.0.confirmation.contact', null);
    }

    public function test_the_buyer_confirming_the_exact_terms_creates_the_deal(): void
    {
        $confirmation = $this->confirmationId();

        $this->buyerConfirm($confirmation, ['contact_phone' => self::BUYER_PHONE])->assertCreated()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.terms_source', 'confirmed_intent')->assertJsonPath('data.source.kind', 'purchase_intent')
            ->assertJsonPath('data.source.confirmation_id', $confirmation)->assertJsonPath('data.terms.price_basis', 'listed')
            ->assertJsonPath('data.terms.unit_price', '3500.00')->assertJsonPath('data.terms.product_total', '35000.00')->assertJsonPath('data.contact', null);

        $deal = MarketplaceDeal::firstOrFail();
        $this->assertSame($confirmation, $deal->confirmation_id);
        $this->assertNull($deal->offer_id);
        $this->assertSame($this->sellerUser->id, $deal->seller_confirmed_by);
        $this->assertSame('converted', $this->confirmation($confirmation)->status->value);
        $this->assertNull($this->confirmation($confirmation)->open_slot);
        $this->assertNotNull($this->intentRow($deal->intent_id)->converted_at);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_confirmation_converted')->count());
    }

    public function test_confirming_twice_never_creates_a_second_deal(): void
    {
        $confirmation = $this->confirmationId();
        $id = $this->buyerConfirm($confirmation)->assertCreated()->json('data.id');

        $this->buyerConfirm($confirmation)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, MarketplaceDeal::count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_created')->count());

        // the seller confirming the same request again while its deal is live is refused, not duplicated
        $this->sellerConfirm($this->intentRow($this->deal($id)->intent_id)->id, ['fulfilment_method' => 'pickup'])->assertStatus(409)->assertJsonPath('code', 'intent_already_converted');
        $this->assertSame(1, MarketplaceDealConfirmation::count());
    }

    public function test_repeating_the_same_seller_confirmation_returns_the_open_one_and_different_terms_need_a_withdrawal(): void
    {
        $intent = $this->intentId('10');
        $first = $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '2500'])->assertCreated()->json('data.id');

        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '2500.00'])->assertOk()->assertJsonPath('data.id', $first);
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertStatus(409)->assertJsonPath('code', 'confirmation_pending')->assertJsonPath('details.confirmation_id', $first);
        $this->assertSame(1, MarketplaceDealConfirmation::count());

        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/deal-confirmations/$first/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/deal-confirmations/$first/withdraw")->assertOk();   // idempotent
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_confirmation_withdrawn')->count());
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertCreated();   // free to confirm afresh

        $this->buyerConfirm($first)->assertStatus(409)->assertJsonPath('code', 'confirmation_withdrawn');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_only_an_owner_or_manager_can_confirm_and_non_members_see_nothing(): void
    {
        $manager = $this->addMember($this->sellerUser, $this->shopId, 'manager');
        $staff = $this->addMember($this->sellerUser, $this->shopId, 'staff');
        $outsider = $this->seller('Outsider');
        $otherShop = $this->activeShop($outsider);
        $intent = $this->intentId('10');

        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'], $staff)->assertForbidden();
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'], $outsider, $this->shopId)->assertNotFound();          // not a member of this shop
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'], $outsider, $otherShop)->assertNotFound();             // another shop's view of this intent
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'], $this->buyer)->assertNotFound();                      // the buyer is no member
        $this->assertSame(0, MarketplaceDealConfirmation::count());

        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'], $manager)->assertCreated();
        $id = MarketplaceDealConfirmation::firstOrFail()->id;
        $this->assertSame($manager->id, $this->confirmation($id)->confirmed_by);
        $this->signInAs($staff)->postJson(self::SELLER."/shops/{$this->shopId}/deal-confirmations/$id/withdraw")->assertForbidden();
    }

    public function test_only_the_buyer_of_the_request_can_confirm_or_read_the_confirmation(): void
    {
        $confirmation = $this->confirmationId();
        $stranger = User::factory()->create();

        $this->buyerConfirm($confirmation, [], $stranger)->assertNotFound();
        $this->signInAs($stranger)->getJson(self::SELLER."/my/deal-confirmations/$confirmation")->assertNotFound();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/my/deal-confirmations/$confirmation/confirm", ['accept_terms' => true])->assertNotFound();
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deal-confirmations/$confirmation")->assertOk()->assertJsonPath('data.status', 'awaiting_buyer');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_buyer_must_accept_the_terms_explicitly(): void
    {
        $confirmation = $this->confirmationId();
        $this->signInAs($this->buyer)->postJson(self::SELLER."/my/deal-confirmations/$confirmation/confirm", [])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        $this->signInAs($this->buyer)->postJson(self::SELLER."/my/deal-confirmations/$confirmation/confirm", ['accept_terms' => false])->assertStatus(422);
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_an_unanswered_confirmation_lapses_and_the_lapse_is_recorded(): void
    {
        $confirmation = $this->confirmationId();
        Carbon::setTestNow(now()->addHours(73));

        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deal-confirmations/$confirmation")->assertOk()->assertJsonPath('data.status', 'lapsed')->assertJsonPath('data.confirmable', false);
        $this->assertSame('awaiting_buyer', $this->confirmation($confirmation)->status->value);   // read time only

        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'confirmation_lapsed');
        $this->assertSame('lapsed', $this->confirmation($confirmation)->status->value);            // write time persists it
        $this->assertNull($this->confirmation($confirmation)->open_slot);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_confirmation_lapsed')->count());
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_sweep_lapses_only_due_confirmations_and_is_repeatable(): void
    {
        $due = $this->confirmationId();
        Carbon::setTestNow(now()->addHours(60));
        $later = $this->confirmationId([], '12', User::factory()->create(['name' => 'Later']));
        Carbon::setTestNow(now()->addHours(20));   // 80h after the first, 20h after the second

        foreach ([1, 2, 3] as $_) {
            $this->assertSame(0, Artisan::call('marketplace:expire-confirmations'));
        }
        $this->assertSame('lapsed', $this->confirmation($due)->status->value);
        $this->assertSame('awaiting_buyer', $this->confirmation($later)->status->value);
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_confirmation_lapsed')->count());
    }

    public function test_a_stale_request_cannot_be_confirmed_by_the_seller(): void
    {
        $intent = $this->intentId('10');
        $version = (int) MarketplaceListing::findOrFail($this->fishListingId)->version;
        $this->signInAs($this->sellerUser)->patchJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->fishListingId}", ['unit_price' => '4000', 'version' => $version])->assertOk();

        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertStatus(409)->assertJsonPath('code', 'intent_stale');
        $this->signInAs($this->sellerUser)->getJson(self::SELLER."/shops/{$this->shopId}/purchase-intents")->assertOk()->assertJsonPath('data.0.is_current', false)->assertJsonPath('data.0.can_confirm', false);

        $this->intentId('10');   // the buyer proceeds again at the new price: current once more
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertCreated()->assertJsonPath('data.terms.unit_price', '4000.00');
    }

    public function test_a_listing_change_after_the_seller_confirmed_voids_the_confirmation_for_good(): void
    {
        $confirmation = $this->confirmationId();
        $version = (int) MarketplaceListing::findOrFail($this->fishListingId)->version;
        $this->signInAs($this->sellerUser)->patchJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->fishListingId}", ['unit_price' => '3600', 'version' => $version])->assertOk();

        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'confirmation_stale');
        $this->assertSame('voided', $this->confirmation($confirmation)->status->value);   // persisted although the request failed
        $this->assertSame('listing_changed', $this->confirmation($confirmation)->void_reason);
        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'confirmation_voided');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_the_buyer_changing_their_request_voids_what_the_seller_confirmed(): void
    {
        $confirmation = $this->confirmationId([], '10');
        $this->intentId('15');   // the same request, a different quantity

        $this->assertSame('voided', $this->confirmation($confirmation)->status->value);
        $this->assertSame('intent_changed', $this->confirmation($confirmation)->void_reason);
        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'confirmation_voided');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_availability_is_checked_when_the_seller_confirms_and_again_when_the_buyer_does(): void
    {
        $intent = $this->intentId('200');
        MarketplaceListing::whereKey($this->fishListingId)->update(['available_quantity' => '100']);
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertStatus(409)->assertJsonPath('code', 'quantity_unavailable')->assertJsonPath('details.declared_available', '100');
        $this->assertSame(0, MarketplaceDealConfirmation::count());

        MarketplaceListing::whereKey($this->fishListingId)->update(['available_quantity' => '250.5']);
        $confirmation = $this->confirmationId([], '200');
        MarketplaceListing::whereKey($this->fishListingId)->update(['available_quantity' => '100']);
        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'quantity_unavailable');
        $this->assertSame('awaiting_buyer', $this->confirmation($confirmation)->status->value);   // not voided: the seller may restore the stock

        MarketplaceListing::whereKey($this->fishListingId)->update(['available_quantity' => '250.5']);
        $this->buyerConfirm($confirmation)->assertCreated();
    }

    public function test_a_paused_listing_or_inactive_shop_blocks_both_steps(): void
    {
        $intent = $this->intentId('10');
        $confirmation = $this->confirmationId();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->fishListingId}/pause")->assertOk();

        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'listing_unavailable');
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertStatus(409);
        $this->assertSame(0, MarketplaceDeal::count());
        $this->assertSame('awaiting_buyer', $this->confirmation($confirmation)->status->value);
    }

    public function test_the_delivery_charge_is_stored_apart_from_the_product_total_and_unknown_means_null(): void
    {
        $intent = $this->intentId('10');
        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery'])->assertCreated()
            ->assertJsonPath('data.fulfilment.delivery_charge.amount', null)->assertJsonPath('data.fulfilment.delivery_charge.display', 'To be agreed directly')
            ->assertJsonPath('data.terms.product_total', '35000.00');
        $id = MarketplaceDealConfirmation::firstOrFail()->id;
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/deal-confirmations/$id/withdraw")->assertOk();

        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '2500.5'])->assertCreated()
            ->assertJsonPath('data.fulfilment.delivery_charge.amount', '2500.50')->assertJsonPath('data.terms.product_total', '35000.00');
        $confirmation = MarketplaceDealConfirmation::where('status', 'awaiting_buyer')->firstOrFail()->id;

        $this->buyerConfirm($confirmation)->assertCreated()
            ->assertJsonPath('data.fulfilment.method', 'seller_delivery')->assertJsonPath('data.fulfilment.delivery_charge.amount', '2500.50')
            ->assertJsonPath('data.fulfilment.delivery_charge.mode', 'agreed_separately')->assertJsonPath('data.fulfilment.delivery_charge.included_in_product_total', false)
            ->assertJsonPath('data.terms.product_total', '35000.00')->assertJsonPath('data.fulfilment.delivery_coverage', ['Oyo', 'Lagos']);
        $this->assertSame('35000.00', (string) MarketplaceDeal::firstOrFail()->product_total);
        $this->assertSame('2500.50', (string) MarketplaceDeal::firstOrFail()->delivery_charge_amount);
    }

    public function test_delivery_charge_and_method_input_is_validated_against_the_listing(): void
    {
        $intent = $this->intentId('10');
        $this->sellerConfirm($intent, [])->assertStatus(422)->assertJsonValidationErrors('fulfilment_method');                                                  // listing offers both
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup', 'delivery_charge' => '500'])->assertStatus(422)->assertJsonValidationErrors('delivery_charge');   // pickup has no charge
        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '12.345'])->assertStatus(422)->assertJsonValidationErrors('delivery_charge');
        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '-5'])->assertStatus(422)->assertJsonValidationErrors('delivery_charge');
        $this->sellerConfirm($intent, ['fulfilment_method' => 'courier'])->assertStatus(422)->assertJsonValidationErrors('fulfilment_method');

        // a listing whose delivery is included in the price accepts no separate charge
        MarketplaceListing::whereKey($this->fishListingId)->update(['delivery_charge' => 'included']);
        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '500'])->assertStatus(422)->assertJsonValidationErrors('delivery_charge');
        $this->sellerConfirm($intent, ['fulfilment_method' => 'seller_delivery'])->assertCreated()->assertJsonPath('data.fulfilment.delivery_charge.display', 'To be agreed directly');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_a_proposed_charge_cannot_survive_a_listing_that_now_includes_delivery(): void
    {
        $confirmation = $this->confirmationId(['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '1500']);
        MarketplaceListing::whereKey($this->fishListingId)->update(['delivery_charge' => 'included']);

        $this->buyerConfirm($confirmation)->assertStatus(409)->assertJsonPath('code', 'confirmation_stale');
        $this->assertSame(0, MarketplaceDeal::count());
    }

    public function test_a_finished_deal_lets_the_buyer_start_again_but_an_active_one_does_not(): void
    {
        $deal = $this->fixedPriceDealId();
        $intent = $this->deal($deal)->intent_id;

        // while the deal is active, proceeding again does not re-arm the request
        $this->intentId('10');
        $this->assertNotNull($this->intentRow($intent)->converted_at);
        $this->sellerConfirm($intent, ['fulfilment_method' => 'pickup'])->assertStatus(409)->assertJsonPath('code', 'intent_already_converted');

        $this->dealAction('cancel', $deal, ['reason' => 'changed_mind'])->assertOk();
        $this->intentId('10');   // the buyer proceeds again
        $this->assertNull($this->intentRow($intent)->converted_at);
        $second = $this->confirmationId();
        $this->buyerConfirm($second)->assertCreated();
        $this->assertSame(2, MarketplaceDeal::count());
        $this->assertSame(2, MarketplaceDeal::distinct('confirmation_id')->count());
    }
}
