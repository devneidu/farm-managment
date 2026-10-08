<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ShopPermission;
use App\Enums\ShopRole;
use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\MarketplaceListing;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** A deal is a summary of agreed terms: it reserves no stock and creates no sale, invoice, payment, finance or inventory record. */
class MarketplaceDealIsolationTest extends DealTestCase
{
    /** @return array<string, int> row count of every table */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_the_whole_deal_lifecycle_only_writes_marketplace_and_audit_tables(): void
    {
        [$b2, $b3, $b4] = User::factory()->count(3)->create()->all();   // created first so the snapshot only sees deal writes
        $admin = $this->admin();
        $stock = [(string) MarketplaceListing::find($this->listingId)->available_quantity, (string) MarketplaceListing::find($this->fishListingId)->available_quantity];
        $before = $this->rowCounts();

        $viaOffer = $this->dealId(['contact_phone' => self::BUYER_PHONE]);
        $viaIntent = $this->fixedPriceDealId(['fulfilment_method' => 'seller_delivery', 'delivery_charge' => '2000'], ['contact_phone' => self::BUYER_PHONE], $b2);
        $this->buyerContact($viaOffer)->assertOk();
        $this->sellerContact($viaIntent)->assertOk();
        $this->dealAction('complete', $viaOffer)->assertOk();
        $this->asSeller('complete', $viaOffer)->assertOk();
        $this->asSeller('cancel', $viaIntent, ['reason' => 'seller_unavailable'])->assertOk();
        $this->dealAction('report', $viaIntent, ['target' => 'other_party', 'reason' => 'no_show'], $b2)->assertCreated();
        $withdrawn = $this->confirmationId([], '10', $b3);
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/deal-confirmations/$withdrawn/withdraw")->assertOk();
        $lapsing = $this->confirmationId([], '10', $b4);
        Carbon::setTestNow(now()->addHours(80));
        Artisan::call('marketplace:expire-confirmations');
        $this->assertSame('lapsed', $this->confirmation($lapsing)->status->value);
        $this->signInAs($admin)->getJson(self::ADMIN."/deals/$viaOffer/contact")->assertOk();

        $changed = array_keys(array_filter($this->rowCounts(), fn (int $n, string $t) => $n !== $before[$t], ARRAY_FILTER_USE_BOTH));
        sort($changed);
        $this->assertSame([
            'audit_logs', 'marketplace_deal_confirmations', 'marketplace_deal_contact_views', 'marketplace_deal_events', 'marketplace_deal_reports', 'marketplace_deals',
            'marketplace_offer_events', 'marketplace_offers', 'marketplace_purchase_intents', 'marketplace_report_events',
        ], $changed, 'deals must not touch inventory, sales, invoices, payments, finance, contacts or any other table');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, InventoryItem::count());
        $this->assertSame($stock, [(string) MarketplaceListing::find($this->listingId)->available_quantity, (string) MarketplaceListing::find($this->fishListingId)->available_quantity]);   // nothing reserved or deducted
    }

    public function test_a_completed_deal_still_posts_nothing_anywhere_else(): void
    {
        $id = $this->dealId();
        $before = $this->rowCounts();
        unset($before['audit_logs'], $before['marketplace_deal_events'], $before['marketplace_deals']);

        $this->dealAction('complete', $id)->assertOk();
        $this->asSeller('complete', $id)->assertOk()->assertJsonPath('data.status', 'completed');

        $after = $this->rowCounts();
        unset($after['audit_logs'], $after['marketplace_deal_events'], $after['marketplace_deals']);
        $this->assertSame($before, $after);
        $this->assertSame(0, Sale::count());
    }

    public function test_responses_state_the_payment_and_inventory_boundary(): void
    {
        $id = $this->dealId();
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/deals/$id")->assertOk()->assertJsonPath('data.terms.total_covers', 'product_only');
        $notice = $this->getJson(self::SELLER."/my/deals/$id")->json('data.notice');
        foreach (['does not collect payment', 'hold funds', 'reserve stock'] as $phrase) {
            $this->assertStringContainsString($phrase, $notice);
        }
        $this->assertStringContainsString('cannot be recalled', $this->buyerContact($id)->json('data.notice'));
    }

    public function test_the_confirmation_sweep_is_scheduled_hourly_and_safe_to_repeat(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $e) => str_contains($e->command, 'marketplace:expire-confirmations'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);

        $confirmation = $this->confirmationId();
        Carbon::setTestNow(now()->addHours(73));
        foreach (range(1, 4) as $_) {
            $this->assertSame(0, Artisan::call('marketplace:expire-confirmations'));
        }
        $this->buyerConfirm($confirmation)->assertStatus(409);                       // a write on the lapsed one adds nothing either
        $this->assertSame(1, AuditLog::where('action', 'marketplace.deal_confirmation_lapsed')->count());
    }

    public function test_shop_roles_hold_the_deal_permissions_the_design_gives_them(): void
    {
        $this->assertTrue(ShopRole::Owner->can(ShopPermission::DealView) && ShopRole::Owner->can(ShopPermission::DealRespond));
        $this->assertTrue(ShopRole::Manager->can(ShopPermission::DealView) && ShopRole::Manager->can(ShopPermission::DealRespond));
        $this->assertTrue(ShopRole::Staff->can(ShopPermission::DealView));
        $this->assertFalse(ShopRole::Staff->can(ShopPermission::DealRespond));
    }

    public function test_the_deal_window_is_an_administrable_setting(): void
    {
        $this->signInAs($this->admin())->putJson('/api/v1/platform-admin/settings/marketplace_deal_confirmation_hours', ['value' => 24])->assertOk()->assertJsonPath('data.value', 24);
        $this->putJson('/api/v1/platform-admin/settings/marketplace_deal_confirmation_hours', ['value' => 0])->assertStatus(422);
        $this->putJson('/api/v1/platform-admin/settings/marketplace_deal_confirmation_hours', ['value' => 721])->assertStatus(422);

        $offer = $this->acceptedOfferId();
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$offer")->assertOk()->assertJsonPath('data.deal_confirmation.window_hours', 24);
    }
}
