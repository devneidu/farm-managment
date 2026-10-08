<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceDealEvent;
use App\Models\MarketplaceDealReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/** What the two parties can do to a deal: self-reported two-sided completion, cancellation, and reports that never change the deal. */
class MarketplaceDealLifecycleTest extends DealTestCase
{
    public function test_completion_needs_both_sides_and_is_always_self_reported(): void
    {
        $id = $this->dealId();

        $this->dealAction('complete', $id)->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.completion.state', 'awaiting_seller')
            ->assertJsonPath('data.completion.verification', 'self_reported')->assertJsonPath('data.can.complete', false);
        $this->assertNotNull($this->deal($id)->buyer_completed_at);
        $this->assertNull($this->deal($id)->completed_at);

        $this->signInAs($this->sellerUser)->getJson(self::SELLER."/shops/{$this->shopId}/deals/$id")->assertOk()
            ->assertJsonPath('data.completion.state', 'awaiting_seller')->assertJsonPath('data.can.complete', true);

        $this->asSeller('complete', $id)->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completion.state', 'completed')->assertJsonPath('data.completion.verification', 'self_reported')
            ->assertJsonPath('data.can.cancel', false);
        $deal = $this->deal($id);
        $this->assertNotNull($deal->completed_at);
        $this->assertSame($this->sellerUser->id, $deal->seller_completed_by);
        $this->assertSame(['created', 'completion_confirmed', 'completion_confirmed', 'completed'], MarketplaceDealEvent::where('deal_id', $id)->orderBy('created_at')->orderBy('id')->pluck('action')->all());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_completed')->count());
    }

    public function test_the_seller_may_report_first_and_repeats_change_nothing(): void
    {
        $id = $this->dealId();
        $this->asSeller('complete', $id)->assertOk()->assertJsonPath('data.completion.state', 'awaiting_buyer')->assertJsonPath('data.status', 'accepted');
        $this->asSeller('complete', $id)->assertOk()->assertJsonPath('data.completion.state', 'awaiting_buyer');
        $this->dealAction('complete', $id)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->dealAction('complete', $id)->assertOk()->assertJsonPath('data.status', 'completed');   // after completion: still a no-op

        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $id)->where('action', 'completed')->count());
        $this->assertSame(2, MarketplaceDealEvent::where('deal_id', $id)->where('action', 'completion_confirmed')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_completed')->count());
    }

    public function test_one_sided_completion_never_closes_a_deal_by_itself(): void
    {
        $id = $this->dealId();
        $this->dealAction('complete', $id)->assertOk();

        Carbon::setTestNow(now()->addDays(60));
        Artisan::call('schedule:run');
        Artisan::call('marketplace:expire-offers');
        Artisan::call('marketplace:expire-confirmations');

        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deals/$id")->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.completion.state', 'awaiting_seller')->assertJsonPath('data.completion.completed_at', null);
        $this->assertNull($this->deal($id)->completed_at);
    }

    public function test_either_party_can_cancel_an_active_deal_with_a_reason(): void
    {
        $byBuyer = $this->dealId();
        $this->dealAction('cancel', $byBuyer, ['reason' => 'changed_mind', 'note' => 'Plans changed.'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation.by', 'buyer')->assertJsonPath('data.cancellation.reason', 'changed_mind')
            ->assertJsonPath('data.cancellation.note', 'Plans changed.')->assertJsonPath('data.contact_available', false)->assertJsonPath('data.can.cancel', false);

        $other = User::factory()->create(['name' => 'Second Buyer']);
        $bySeller = $this->dealId([], $other);
        $this->asSeller('cancel', $bySeller, ['reason' => 'seller_unavailable'])->assertOk()->assertJsonPath('data.cancellation.by', 'seller');
        $this->assertSame($this->sellerUser->id, $this->deal($bySeller)->cancelled_by);
        $this->assertSame(2, AuditLog::where('action', 'marketplace.deal_cancelled')->count());
    }

    public function test_cancel_validates_its_input_and_is_idempotent(): void
    {
        $id = $this->dealId();
        $this->dealAction('cancel', $id, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->dealAction('cancel', $id, ['reason' => 'because'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->dealAction('cancel', $id, ['reason' => 'other', 'note' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->assertSame('accepted', $this->deal($id)->status->value);

        $this->dealAction('cancel', $id, ['reason' => 'changed_mind'])->assertOk();
        $this->dealAction('cancel', $id, ['reason' => 'other'])->assertOk()->assertJsonPath('data.cancellation.reason', 'changed_mind');   // the first reason stands
        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $id)->where('action', 'cancelled')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_cancelled')->count());
    }

    public function test_completed_deals_cannot_be_cancelled_and_cancelled_ones_cannot_be_completed(): void
    {
        $done = $this->dealId();
        $this->dealAction('complete', $done)->assertOk();
        $this->asSeller('complete', $done)->assertOk();
        $this->dealAction('cancel', $done, ['reason' => 'changed_mind'])->assertStatus(409)->assertJsonPath('code', 'deal_not_open');
        $this->asSeller('cancel', $done, ['reason' => 'changed_mind'])->assertStatus(409)->assertJsonPath('code', 'deal_not_open');
        $this->assertSame('completed', $this->deal($done)->status->value);

        $gone = $this->dealId([], User::factory()->create());
        $this->asSeller('cancel', $gone, ['reason' => 'other'])->assertOk();
        $this->asSeller('complete', $gone)->assertStatus(409)->assertJsonPath('code', 'deal_not_open');
        $this->assertNull($this->deal($gone)->seller_completed_at);
    }

    public function test_cancelling_after_one_side_completed_is_allowed(): void
    {
        $id = $this->dealId();
        $this->dealAction('complete', $id)->assertOk();
        $this->asSeller('cancel', $id, ['reason' => 'no_agreement_on_terms'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.completion.state', 'cancelled');
    }

    public function test_staff_can_see_deals_but_not_act_on_them(): void
    {
        $staff = $this->addMember($this->sellerUser, $this->shopId, 'staff');
        $manager = $this->addMember($this->sellerUser, $this->shopId, 'manager');
        $id = $this->dealId();

        $this->signInAs($staff)->getJson(self::SELLER."/shops/{$this->shopId}/deals")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.cancel', false);
        $this->signInAs($staff)->getJson(self::SELLER."/shops/{$this->shopId}/deals/$id")->assertOk()->assertJsonPath('data.can.complete', false)->assertJsonPath('data.can.view_contact', false);
        $this->asSeller('complete', $id, [], $staff)->assertForbidden();
        $this->asSeller('cancel', $id, ['reason' => 'other'], $staff)->assertForbidden();
        $this->asSeller('report', $id, ['target' => 'deal', 'reason' => 'other'], $staff)->assertForbidden();
        $this->sellerContact($id, $staff)->assertForbidden();

        $this->asSeller('complete', $id, [], $manager)->assertOk();
        $this->assertSame($manager->id, $this->deal($id)->seller_completed_by);
        $this->assertSame('accepted', $this->deal($id)->status->value);
    }

    public function test_buyers_and_shops_reach_only_their_own_deals(): void
    {
        $id = $this->dealId();
        $stranger = User::factory()->create();
        $outsider = $this->seller('Outsider');
        $otherShop = $this->activeShop($outsider);

        foreach (['complete' => [], 'cancel' => ['reason' => 'other'], 'report' => ['target' => 'deal', 'reason' => 'other']] as $action => $body) {
            $this->dealAction($action, $id, $body, $stranger)->assertNotFound();
            $this->asSeller($action, $id, $body, $outsider)->assertNotFound();                          // not a member of this shop
            $this->dealAction($action, $id, $body, $outsider, $otherShop)->assertNotFound();            // another shop's door
            $this->dealAction($action, $id, $body, $this->sellerUser)->assertNotFound();                // the seller is not the buyer
        }
        $this->signInAs($stranger)->getJson(self::SELLER."/my/deals/$id")->assertNotFound();
        $this->signInAs($stranger)->getJson(self::SELLER.'/my/deals')->assertOk()->assertJsonCount(0, 'data');
        $this->signInAs($outsider)->getJson(self::SELLER."/shops/$otherShop/deals/$id")->assertNotFound();
        $this->signInAs($outsider)->getJson(self::SELLER."/shops/$otherShop/deals")->assertOk()->assertJsonCount(0, 'data');
        $this->signInAs($outsider)->getJson(self::SELLER."/shops/{$this->shopId}/deals")->assertNotFound();
        $this->signInAs($this->buyer)->getJson(self::SELLER."/shops/{$this->shopId}/deals")->assertNotFound();
        $this->assertSame('accepted', $this->deal($id)->status->value);
        $this->assertNull($this->deal($id)->buyer_completed_at);
    }

    public function test_lists_filter_by_status_and_listing(): void
    {
        $open = $this->dealId();
        $cancelled = $this->dealId([], User::factory()->create(['name' => 'B2']));
        $this->asSeller('cancel', $cancelled, ['reason' => 'other'])->assertOk();
        $fish = $this->fixedPriceDealId([], [], User::factory()->create(['name' => 'B3']));

        $this->signInAs($this->sellerUser);
        $this->getJson(self::SELLER."/shops/{$this->shopId}/deals")->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson(self::SELLER."/shops/{$this->shopId}/deals?status=cancelled")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $cancelled);
        $this->getJson(self::SELLER."/shops/{$this->shopId}/deals?listing={$this->fishListingId}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $fish);
        $this->getJson(self::SELLER."/shops/{$this->shopId}/deals?status=bogus")->assertStatus(422);
        $this->signInAs($this->buyer)->getJson(self::SELLER.'/my/deals?status=accepted')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $open);
    }

    // ------------------------------------------------------------------ reports

    public function test_a_report_preserves_the_deal_and_changes_nothing_about_it(): void
    {
        $id = $this->dealId();
        $before = $this->deal($id)->only(['status', 'quantity', 'unit_price', 'product_total', 'buyer_completed_at', 'seller_completed_at']);

        $this->dealAction('report', $id, ['target' => 'other_party', 'reason' => 'no_show', 'description' => 'Did not arrive for pickup.'])->assertCreated()
            ->assertJsonPath('data.target', 'seller')->assertJsonPath('data.reason', 'no_show')->assertJsonPath('data.status', 'open')->assertJsonPath('data.deal_status_at_report', 'accepted');

        $this->assertSame($before, $this->deal($id)->only(['status', 'quantity', 'unit_price', 'product_total', 'buyer_completed_at', 'seller_completed_at']));
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deals/$id")->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.can.complete', true)->assertJsonPath('data.can.cancel', true)->assertJsonCount(1, 'data.my_reports');

        // completion and cancellation are not frozen
        $this->dealAction('complete', $id)->assertOk();
        $this->asSeller('complete', $id)->assertOk()->assertJsonPath('data.status', 'completed');
        $report = MarketplaceDealReport::firstOrFail();
        $this->assertMatchesRegularExpression('/^DRP-\d{4}-\d{5}$/', $report->reference);
        $this->assertSame('buyer', $report->reporter_side);
        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $id)->where('action', 'reported')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_reported')->count());
    }

    public function test_a_report_is_allowed_in_any_state_including_after_cancellation(): void
    {
        $id = $this->dealId();
        $this->dealAction('cancel', $id, ['reason' => 'seller_unavailable'])->assertOk();
        $this->dealAction('report', $id, ['target' => 'deal', 'reason' => 'misrepresented_product'])->assertCreated()->assertJsonPath('data.deal_status_at_report', 'cancelled');
        $this->asSeller('report', $id, ['target' => 'other_party', 'reason' => 'abusive_behaviour', 'description' => 'Rude messages.'])->assertCreated()->assertJsonPath('data.target', 'buyer');
        $this->assertSame('cancelled', $this->deal($id)->status->value);
        $this->assertSame(2, MarketplaceDealReport::count());

        $done = $this->dealId([], User::factory()->create());
        $this->dealAction('complete', $done, [], $this->deal($done)->buyer)->assertOk();
        $this->asSeller('complete', $done)->assertOk();
        $this->dealAction('report', $done, ['target' => 'other_party', 'reason' => 'suspected_fraud'], $this->deal($done)->buyer)->assertCreated();
        $this->assertSame('completed', $this->deal($done)->status->value);
    }

    public function test_reporting_is_validated_and_idempotent_per_target(): void
    {
        $id = $this->dealId();
        $this->dealAction('report', $id, [])->assertStatus(422)->assertJsonValidationErrors(['target', 'reason']);
        $this->dealAction('report', $id, ['target' => 'someone', 'reason' => 'no_show'])->assertStatus(422)->assertJsonValidationErrors('target');
        $this->dealAction('report', $id, ['target' => 'deal', 'reason' => 'refund_me'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->dealAction('report', $id, ['target' => 'deal', 'reason' => 'other', 'description' => str_repeat('x', 2001)])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->assertSame(0, MarketplaceDealReport::count());

        $first = $this->dealAction('report', $id, ['target' => 'deal', 'reason' => 'other'])->assertCreated()->json('data.id');
        $this->dealAction('report', $id, ['target' => 'deal', 'reason' => 'no_show'])->assertOk()->assertJsonPath('data.id', $first);   // same target: the first stands
        $this->dealAction('report', $id, ['target' => 'other_party', 'reason' => 'no_show'])->assertCreated();                           // a different target is a separate report
        $this->assertSame(2, MarketplaceDealReport::count());
        $this->assertSame(2, MarketplaceDealEvent::where('deal_id', $id)->where('action', 'reported')->count());
    }

    public function test_the_other_party_never_learns_that_they_were_reported(): void
    {
        $id = $this->dealId();
        $this->dealAction('report', $id, ['target' => 'other_party', 'reason' => 'suspected_fraud', 'description' => 'Secret allegation text.'])->assertCreated();

        $sellerView = $this->signInAs($this->sellerUser)->getJson(self::SELLER."/shops/{$this->shopId}/deals/$id")->assertOk();
        $sellerView->assertJsonCount(0, 'data.my_reports');
        $this->assertSame(['created'], array_column($sellerView->json('data.history'), 'action'));
        $this->assertStringNotContainsString('Secret allegation', $sellerView->getContent());
        $this->assertStringNotContainsString('suspected_fraud', $sellerView->getContent());

        $buyerView = $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deals/$id")->assertOk();
        $this->assertSame(['created', 'reported'], array_column($buyerView->json('data.history'), 'action'));   // the reporter sees their own
    }

    public function test_platform_admins_see_every_deal_and_report_but_cannot_change_them(): void
    {
        $id = $this->dealId();
        $this->dealAction('report', $id, ['target' => 'other_party', 'reason' => 'no_show', 'description' => 'No show.'])->assertCreated();
        $this->asSeller('report', $id, ['target' => 'deal', 'reason' => 'terms_changed'])->assertCreated();
        $clean = $this->dealId([], User::factory()->create(['name' => 'Clean Buyer']));
        $admin = $this->admin();

        $this->signInAs($admin)->getJson(self::ADMIN.'/deals')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(self::ADMIN.'/deals?reported=true')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.report_count', 2);
        $this->getJson(self::ADMIN.'/deals?reported=false')->assertOk()->assertJsonPath('data.0.id', $clean);
        $this->getJson(self::ADMIN.'/deals?status=cancelled')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::ADMIN.'/deals?q='.$this->deal($id)->reference)->assertOk()->assertJsonPath('meta.total', 1);

        $detail = $this->getJson(self::ADMIN."/deals/$id")->assertOk()->assertJsonPath('data.report_count', 2)->assertJsonCount(2, 'data.reports')->assertJsonPath('data.contact', null);
        $this->assertSame(['created', 'reported', 'reported'], array_column($detail->json('data.history'), 'action'));
        $this->assertSame('Bola Buyer', $detail->json('data.reports.0.reporter.name'));
        $this->getJson(self::ADMIN.'/deal-reports')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.deal.id', $id);
        $this->getJson(self::ADMIN."/deal-reports?deal_id=$clean")->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(self::ADMIN.'/deal-reports?reason=no_show')->assertOk()->assertJsonPath('meta.total', 1);

        // read-only: no verb other than GET exists for admins
        foreach (['post', 'patch', 'delete', 'put'] as $verb) {
            $this->{$verb.'Json'}(self::ADMIN."/deals/$id", ['status' => 'cancelled'])->assertStatus(405);
        }
        $this->postJson(self::ADMIN."/deals/$id/cancel")->assertNotFound();
        $this->assertSame('accepted', $this->deal($id)->status->value);
    }

    public function test_ordinary_users_and_farm_roles_cannot_use_the_admin_deal_endpoints(): void
    {
        $id = $this->dealId();
        foreach ([$this->buyer, $this->sellerUser] as $user) {
            $this->signInAs($user)->getJson(self::ADMIN.'/deals')->assertForbidden();
            $this->getJson(self::ADMIN."/deals/$id")->assertForbidden();
            $this->getJson(self::ADMIN."/deals/$id/contact")->assertForbidden();
            $this->getJson(self::ADMIN.'/deal-reports')->assertForbidden();
        }
    }
}
