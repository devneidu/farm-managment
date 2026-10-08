<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplacePromotion;
use App\Models\MarketplaceServicePayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/** Promoted listings: purchase, activation, ranking, the per-page cap, disclosure, expiry and the no-benefit cases. */
class MarketplacePromotionTest extends MonetisationTestCase
{
    /** Buys and activates a promotion; returns the payment reference. */
    private function promote(User $owner, string $shop, string $listing, string $package, int $kobo = 300000): string
    {
        $ref = $this->checkoutPromotion($owner, $shop, $listing, $package);
        $this->gatewayReports($ref, 'success', $kobo);
        $this->verify($owner, $shop, $ref)->assertOk();

        return $ref;
    }

    private function discovery(string $query = ''): array
    {
        $who = $this->app['auth']->guard('web')->user();
        $this->app['auth']->forgetGuards();
        $rows = $this->getJson(self::PUBLIC.($query ? "?$query" : ''))->assertOk()->json('data');
        if ($who) {
            $this->signInAs($who);
        }

        return $rows;
    }

    public function test_promotions_are_disabled_until_the_flag_is_on(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $listing = $this->live($shop, $this->chicken());
        $package = $this->packageOnSale();
        $this->setFlag('marketplace_promotions', false);

        $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/listings/$listing/promotions/checkout", ['package_id' => $package])->assertStatus(409)->assertJsonPath('code', 'monetisation_disabled');
        $this->getJson(self::SELLER."/shops/$shop/promotion-packages")->assertOk()->assertJsonPath('meta.features.promotions', false)->assertJsonCount(1, 'data');
    }

    public function test_checkout_rules_published_own_listing_active_package_and_one_promotion_at_a_time(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $live = $this->live($shop, $this->chicken());
        $draft = $this->createId($shop, $this->yam());
        $package = $this->packageOnSale('3000', 7);
        $other = $this->activeShop($this->seller('Other'));
        $foreign = $this->live($other, $this->chicken(['title' => 'Foreign']));

        $this->signInAs($owner);
        $this->postJson(self::SELLER."/shops/$shop/listings/$draft/promotions/checkout", ['package_id' => $package])->assertStatus(409)->assertJsonPath('code', 'listing_not_promotable');
        $this->postJson(self::SELLER."/shops/$shop/listings/$foreign/promotions/checkout", ['package_id' => $package])->assertNotFound();   // another shop's listing
        $this->postJson(self::SELLER."/shops/$shop/listings/$live/promotions/checkout", ['package_id' => '019e0000-0000-7000-8000-000000000000'])->assertStatus(409)->assertJsonPath('code', 'package_not_available');
        $this->postJson(self::SELLER."/shops/$shop/listings/$live/promotions/checkout", [])->assertStatus(422)->assertJsonValidationErrors('package_id');

        $this->assertDatabaseMissing('marketplace_promotions', ['listing_id' => $live]);
        $ref = $this->promote($owner, $shop, $live, $package);
        $this->assertDatabaseHas('marketplace_service_payments', ['reference' => $ref, 'amount' => '3000.00', 'purpose' => 'promotion', 'listing_id' => $live]);
        $p = MarketplacePromotion::firstOrFail();
        $this->assertMatchesRegularExpression('/^MPR-\d{4}-\d{5}$/', $p->reference);
        $this->assertSame(7, (int) round($p->starts_at->diffInDays($p->expires_at)));

        $this->postJson(self::SELLER."/shops/$shop/listings/$live/promotions/checkout", ['package_id' => $package])->assertStatus(409)->assertJsonPath('code', 'promotion_active');
        $this->getJson(self::SELLER."/shops/$shop/promotions")->assertOk()->assertJsonPath('data.0.state', 'running')->assertJsonPath('data.0.benefit_active', true)
            ->assertJsonPath('data.0.listing.id', $live);
    }

    public function test_a_promoted_listing_leads_discovery_with_a_sponsored_label_while_organic_listings_stay_visible(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale();
        $ids = [];
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $t) {
            $ids[$t] = $this->live($shop, $this->chicken(['title' => $t]));
        }
        $before = array_column($this->discovery(), 'slug');
        $this->assertSame($this->slug($ids['Alpha']), end($before), 'oldest is last without promotion');

        $this->promote($owner, $shop, $ids['Alpha'], $package);
        $rows = $this->discovery();
        $this->assertSame($this->slug($ids['Alpha']), $rows[0]['slug']);
        $this->assertSame(['label' => 'Sponsored'], $rows[0]['promotion']);
        $this->assertSame([null, null, null], array_column(array_slice($rows, 1), 'promotion'));
        $this->assertEqualsCanonicalizing($before, array_column($rows, 'slug'), 'every organic listing is still there');
        $this->assertSame(['Delta', 'Charlie', 'Bravo'], array_column(array_slice($rows, 1), 'title'), 'organic order is untouched');
        $this->assertSame(['label' => 'Sponsored'], $this->getJson(self::PUBLIC.'/'.$this->slug($ids['Alpha']))->json('data.promotion'));
        $this->assertNull($this->getJson(self::PUBLIC.'/'.$this->slug($ids['Bravo']))->json('data.promotion'));

        // Price sorts are never bent by payment (equal prices fall back to newest-first, so the oldest listing stays last), though the disclosure stays.
        $byPrice = $this->discovery('sort=price_asc');
        $this->assertSame(['Delta', 'Charlie', 'Bravo', 'Alpha'], array_column($byPrice, 'title'));
        $this->assertSame('Sponsored', $byPrice[3]['promotion']['label']);
    }

    public function test_no_more_than_the_cap_lead_a_page_and_every_listing_appears_exactly_once_across_pages(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale();
        $ids = [];
        for ($i = 1; $i <= 8; $i++) {
            $ids[$i] = $this->live($shop, $this->chicken(['title' => "Item $i"]));
        }
        $promoted = [$ids[1], $ids[2], $ids[3], $ids[4], $ids[5]];
        foreach ($promoted as $id) {
            $this->promote($owner, $shop, $id, $package);
        }
        $promotedSlugs = array_map(fn ($id) => $this->slug($id), $promoted);

        $rows = $this->discovery();
        $this->assertCount(8, $rows);
        $this->assertCount(3, array_intersect(array_column(array_slice($rows, 0, 3), 'slug'), $promotedSlugs), 'the default cap of 3 leads the page');
        $this->assertSame(['Item 8', 'Item 7', 'Item 6'], array_slice(array_column(array_slice($rows, 3), 'title'), 0, 3), 'organic listings follow in their normal order, not hidden');
        $this->assertCount(5, array_filter($rows, fn ($r) => $r['promotion'] !== null), 'every paid listing is labelled, led or not');

        // Pagination: no duplicates, nothing dropped.
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $res = $this->getJson(self::PUBLIC."?per_page=3&page=$page")->assertOk();
            $this->assertSame(8, $res->json('meta.total'));
            $seen = array_merge($seen, array_column($res->json('data'), 'slug'));
        }
        $this->assertCount(8, array_unique($seen));
        $this->assertCount(3, array_intersect(array_slice($seen, 0, 3), $promotedSlugs));

        // The cap is a platform setting; 0 turns priority placement off while the label stays.
        $this->signInAs($this->admin())->putJson(self::PLATFORM.'/settings/marketplace_max_promoted_per_page', ['value' => 1])->assertOk();
        $this->assertCount(1, array_intersect(array_column(array_slice($this->discovery(), 0, 3), 'slug'), $promotedSlugs));
        $this->putJson(self::PLATFORM.'/settings/marketplace_max_promoted_per_page', ['value' => 0])->assertOk();
        $this->assertSame(['Item 8', 'Item 7', 'Item 6'], array_column(array_slice($this->discovery(), 0, 3), 'title'));
        $this->putJson(self::PLATFORM.'/settings/marketplace_max_promoted_per_page', ['value' => 11])->assertStatus(422);
    }

    public function test_an_expired_promotion_loses_its_priority_and_label_without_any_job_and_can_be_bought_again(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale('3000', 7);
        $old = $this->live($shop, $this->chicken(['title' => 'Old']));
        $this->live($shop, $this->chicken(['title' => 'New']));
        $this->promote($owner, $shop, $old, $package);
        $this->assertSame('Old', $this->discovery()[0]['title']);

        $this->travel(6)->days();
        $this->assertSame('Old', $this->discovery()[0]['title']);
        $this->travel(2)->days();   // day 8
        $rows = $this->discovery();
        $this->assertSame(['New', 'Old'], array_column($rows, 'title'));
        $this->assertSame([null, null], array_column($rows, 'promotion'));
        $this->getJson(self::SELLER."/shops/$shop/promotions?state=ended")->assertOk()->assertJsonPath('data.0.state', 'expired')->assertJsonPath('data.0.benefit_active', false);
        $this->assertSame('active', MarketplacePromotion::first()->status, 'no job flipped it: expiry is read from the paid window');

        $second = $this->promote($owner, $shop, $old, $package);   // the listing is free to be promoted again
        $this->assertSame('Old', $this->discovery()[0]['title']);
        $this->assertSame(2, MarketplacePromotion::count());
        $this->assertNotNull($second);
    }

    public function test_a_suspended_shop_or_restricted_or_paused_listing_gets_no_promotion_benefit_and_the_window_is_not_extended(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale('3000', 7);
        $a = $this->live($shop, $this->chicken(['title' => 'Alpha']));
        $b = $this->live($shop, $this->chicken(['title' => 'Bravo']));
        $this->promote($owner, $shop, $a, $package);
        $this->promote($owner, $shop, $b, $package, 300000);
        $expires = MarketplacePromotion::where('listing_id', $a)->value('expires_at');

        $admin = $this->admin();
        $this->signInAs($admin)->postJson(self::ADMIN."/listings/$a/restrict", ['reason' => 'Prohibited product'])->assertOk();
        $this->assertNotContains($this->slug($a), $this->publicSlugs());
        $this->signInAs($owner)->getJson(self::SELLER."/shops/$shop/promotions")->assertOk()->assertJsonPath('data.1.listing.id', $a)->assertJsonPath('data.1.state', 'running')->assertJsonPath('data.1.benefit_active', false);

        $this->signInAs($admin)->postJson(self::ADMIN."/shops/$shop/suspend", ['reason' => 'Under investigation'])->assertOk();
        $this->assertSame([], $this->discovery(), 'a suspended shop has no listings, so no promotion');
        $this->signInAs($owner)->getJson(self::SELLER."/shops/$shop/promotions")->assertJsonPath('data.0.benefit_active', false);

        $this->signInAs($admin)->postJson(self::ADMIN."/shops/$shop/reinstate")->assertOk();
        $this->assertTrue(MarketplacePromotion::where('listing_id', $a)->value('expires_at')->equalTo($expires), 'the paid window is neither extended nor refunded');
        $this->assertSame(['Bravo'], array_column($this->discovery(), 'title'));
        $this->assertSame('Sponsored', $this->discovery()[0]['promotion']['label']);
    }

    public function test_a_payment_confirmed_after_the_shop_was_suspended_is_kept_for_support_and_activates_nothing(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale();
        $listing = $this->live($shop, $this->chicken());
        $ref = $this->checkoutPromotion($owner, $shop, $listing, $package);

        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/$shop/suspend", ['reason' => 'Under investigation'])->assertOk();
        $this->gatewayReports($ref, 'success', 300000);
        $this->webhook($ref)->assertOk();

        $this->assertSame(0, MarketplacePromotion::count());
        $this->assertDatabaseHas('marketplace_service_payments', ['reference' => $ref, 'status' => 'paid', 'settled_at' => null, 'settlement_issue' => 'shop_not_eligible']);
        $this->signInAs($this->admin())->getJson(self::PLATFORM.'/marketplace/service-payments?needs_attention=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.settlement_issue', 'shop_not_eligible');
        $this->signInAs($owner)->getJson(self::SELLER."/shops/$shop/payments")->assertOk()->assertJsonPath('data.0.needs_attention', true)->assertJsonPath('data.0.benefit_granted', false);
    }

    public function test_the_database_refuses_two_live_promotions_on_one_listing_and_a_racing_payment_is_flagged_not_activated(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale();
        $listing = $this->live($shop, $this->chicken());
        // Two checkouts opened while nothing was running...
        $first = $this->checkoutPromotion($owner, $shop, $listing, $package);
        MarketplaceServicePayment::where('reference', $first)->update(['created_at' => now()->subHour()]);   // outside the reuse window: a distinct second payment
        $second = $this->checkoutPromotion($owner, $shop, $listing, $package);
        $this->assertNotSame($first, $second);

        // ...both are paid. Only the first can take the listing.
        $this->gatewayReports($first, 'success', 300000);
        $this->gatewayReports($second, 'success', 300000);
        $this->webhook($first)->assertOk();
        $this->webhook($second)->assertOk();

        $this->assertSame(1, MarketplacePromotion::count());
        $this->assertDatabaseHas('marketplace_service_payments', ['reference' => $second, 'status' => 'paid', 'settled_at' => null, 'settlement_issue' => 'listing_already_promoted']);
        $this->expectException(UniqueConstraintViolationException::class);
        MarketplacePromotion::first()->replicate(['reference', 'payment_id'])->forceFill(['reference' => 'MPR-2026-77777', 'payment_id' => MarketplaceServicePayment::where('reference', $second)->value('id')])->save();
    }

    public function test_admins_configure_packages_and_cancel_a_promotion_which_frees_the_listing(): void
    {
        $owner = $this->seller();
        $shop = $this->activeShop($owner);
        $package = $this->packageOnSale('3000', 7);
        $listing = $this->live($shop, $this->chicken());
        $this->promote($owner, $shop, $listing, $package);
        $promotion = MarketplacePromotion::first();

        $admin = $this->signInAs($this->admin());
        $admin->postJson(self::PLATFORM.'/marketplace/promotion-packages', ['code' => 'featured_week', 'name' => 'Dup', 'duration_days' => 7, 'amount' => '1'])->assertStatus(422);
        $admin->postJson(self::PLATFORM.'/marketplace/promotion-packages', ['code' => 'Bad Code', 'name' => 'X', 'duration_days' => 0, 'amount' => '0'])->assertStatus(422);
        $admin->patchJson(self::PLATFORM."/marketplace/promotion-packages/$package", ['amount' => '4500.50'])->assertOk()->assertJsonPath('data.amount', '4500.50');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.marketplace_promotion_package_updated', 'resource_id' => $package]);
        $this->assertSame('3000.00', (string) $promotion->refresh()->amount, 'a running promotion keeps the price it was bought at');

        $admin->getJson(self::PLATFORM.'/marketplace/promotions?state=running')->assertOk()->assertJsonCount(1, 'data');
        $admin->postJson(self::PLATFORM."/marketplace/promotions/{$promotion->id}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $admin->postJson(self::PLATFORM."/marketplace/promotions/{$promotion->id}/cancel", ['reason' => 'Misleading listing'])->assertOk()->assertJsonPath('data.state', 'cancelled');
        $admin->postJson(self::PLATFORM."/marketplace/promotions/{$promotion->id}/cancel", ['reason' => 'Misleading listing'])->assertOk();   // no-op
        $this->assertSame(1, AuditLog::where('action', 'platform.marketplace_promotion_cancelled')->count());
        $this->assertSame([null], array_column($this->discovery(), 'promotion'));

        $admin->patchJson(self::PLATFORM."/marketplace/promotion-packages/$package", ['is_active' => false])->assertOk();
        $this->signInAs($owner)->postJson(self::SELLER."/shops/$shop/listings/$listing/promotions/checkout", ['package_id' => $package])->assertStatus(409)->assertJsonPath('code', 'package_not_available');
        $this->getJson(self::SELLER."/shops/$shop/promotion-packages")->assertOk()->assertJsonCount(0, 'data');
    }
}
