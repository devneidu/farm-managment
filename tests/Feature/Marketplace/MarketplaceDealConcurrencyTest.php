<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ConfirmationStatus;
use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopRole;
use App\Enums\ShopStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplaceDealEvent;
use App\Models\MarketplaceDealReport;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplacePurchaseIntent;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\Species;
use App\Models\Unit;
use App\Models\User;
use App\Services\Marketplace\MarketplaceDealLifecycle;
use App\Services\Marketplace\MarketplaceDealService;
use App\Services\Marketplace\MarketplaceOfferService;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REAL concurrency for deals: several operating-system processes (pcntl_fork), each on its OWN database connection, released together by a barrier and
 * committing for real. Fixtures are committed and removed in tearDown; the class never uses RefreshDatabase (see MarketplaceOfferConcurrencyTest).
 */
class MarketplaceDealConcurrencyTest extends TestCase
{
    private string $dir;

    private MarketplaceShop $shop;

    private MarketplaceListing $listing;

    private User $seller;

    private User $manager;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency tests.');
        }
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        $this->dir = sys_get_temp_dir().'/deal-race-'.Str::random(8);
        mkdir($this->dir);

        $this->seller = User::factory()->create(['name' => 'Race Seller']);
        $this->manager = User::factory()->create(['name' => 'Race Manager']);
        $this->shop = MarketplaceShop::create([
            'name' => 'Deal Race Shop', 'slug' => 'deal-race-'.Str::lower(Str::random(6)), 'reference' => 'SHP-9998-'.random_int(10000, 99999), 'seller_type' => 'business', 'categories' => [],
            'created_by' => $this->seller->id, 'status' => ShopStatus::Active, 'verification_status' => 'unverified', 'state' => 'Oyo', 'city' => 'Ibadan', 'country_code' => 'NG',
            'contact_phone' => '+2348031234567', 'preferred_contact_method' => 'phone',
        ]);
        MarketplaceShopMember::create(['shop_id' => $this->shop->id, 'user_id' => $this->seller->id, 'role' => ShopRole::Owner, 'added_by' => $this->seller->id]);
        MarketplaceShopMember::create(['shop_id' => $this->shop->id, 'user_id' => $this->manager->id, 'role' => ShopRole::Manager, 'added_by' => $this->seller->id]);
        $this->listing = new MarketplaceListing([
            'title' => 'Deal race chickens', 'product_kind' => 'livestock', 'species_id' => Species::where('code', 'chicken')->value('id'), 'unit_id' => Unit::where('code', 'head')->value('id'),
            'unit_price' => '8000.00', 'currency' => 'NGN', 'available_quantity' => '100', 'min_order_quantity' => '1', 'negotiable' => true, 'fulfilment' => 'pickup', 'state' => 'Oyo', 'city' => 'Ibadan',
        ]);
        $this->listing->forceFill(['reference' => 'LST-9998-'.random_int(10000, 99999), 'slug' => 'deal-race-chickens-'.Str::lower(Str::random(6)), 'shop_id' => $this->shop->id,
            'created_by' => $this->seller->id, 'status' => ListingStatus::Published, 'published_at' => now(), 'version' => 1])->save();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            DB::reconnect();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['marketplace_deal_contact_views', 'marketplace_deal_reports', 'marketplace_deal_events', 'marketplace_deals', 'marketplace_deal_confirmations', 'marketplace_offer_events',
                'marketplace_offers', 'marketplace_purchase_intents', 'marketplace_listing_events', 'marketplace_listing_images', 'marketplace_listings', 'marketplace_shop_members',
                'marketplace_shops', 'audit_logs', 'users'] as $table) {
                DB::table($table)->delete();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            foreach (glob($this->dir.'/*') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    /**
     * Runs every closure in its own process, all released at the same moment, and returns their results in order (`ok` or `error:<code>`).
     *
     * @param  list<callable(): mixed>  $jobs
     * @return list<string>
     */
    private function race(array $jobs): array
    {
        DB::disconnect();
        $pids = [];
        foreach ($jobs as $i => $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                try {
                    $out = 'error:crash';
                    try {
                        DB::reconnect();
                        DB::select('SELECT 1');
                        touch("{$this->dir}/ready-$i");
                        $deadline = microtime(true) + 30;
                        while (! file_exists("{$this->dir}/go") && microtime(true) < $deadline) {
                            usleep(200);
                        }
                        $job();
                        $out = 'ok';
                    } catch (ApiHttpException $e) {
                        $out = 'error:'.$e->errorCode;
                    } catch (\Throwable $e) {
                        $out = 'error:'.get_class($e).':'.$e->getMessage();
                    }
                    file_put_contents("{$this->dir}/result-$i", $out);
                } finally {
                    posix_kill(getmypid(), SIGKILL);
                }
            }
            $pids[$i] = $pid;
        }
        $deadline = microtime(true) + 30;
        while (count(glob("{$this->dir}/ready-*")) < count($jobs) && microtime(true) < $deadline) {
            usleep(1000);
        }
        touch("{$this->dir}/go");
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $deadline = microtime(true) + 30;
        while (count(glob("{$this->dir}/result-*")) < count($jobs) && microtime(true) < $deadline) {
            usleep(1000);
        }
        DB::reconnect();
        $results = array_map(fn ($i) => (string) @file_get_contents("{$this->dir}/result-$i"), array_keys($jobs));
        foreach (glob("{$this->dir}/{ready,result}-*", GLOB_BRACE) ?: [] as $f) {
            unlink($f);   // a test may race more than once
        }
        @unlink("{$this->dir}/go");

        return $results;
    }

    // ------------------------------------------------------------------ fixtures

    private function buyer(string $name = 'Racer'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    private function acceptedOffer(User $buyer): MarketplaceOffer
    {
        $o = new MarketplaceOffer([
            'quantity' => '10', 'unit_price' => '7000', 'total_amount' => '70000', 'currency' => 'NGN', 'listing_title' => 'Deal race chickens', 'listing_version' => 1,
            'listed_unit_price' => '8000', 'unit_id' => $this->listing->unit_id, 'unit_code' => 'head', 'product_kind' => 'livestock', 'species_id' => $this->listing->species_id,
            'product_name' => 'Chicken', 'listing_available_quantity' => '100', 'listing_min_order_quantity' => '1', 'expires_at' => now()->addDay(),
        ]);
        $o->forceFill(['reference' => 'OFR-9998-'.random_int(10000, 99999), 'listing_id' => $this->listing->id, 'shop_id' => $this->shop->id, 'buyer_id' => $buyer->id, 'attempt_no' => 1,
            'status' => OfferStatus::Accepted, 'pending_slot' => null, 'responded_at' => now(), 'responded_by' => $this->seller->id])->save();

        return $o;
    }

    private function intent(User $buyer, string $quantity = '10'): MarketplacePurchaseIntent
    {
        $i = new MarketplacePurchaseIntent([
            'quantity' => $quantity, 'listed_unit_price' => '8000', 'total_amount' => (string) (8000 * (int) $quantity), 'currency' => 'NGN', 'unit_id' => $this->listing->unit_id,
            'unit_code' => 'head', 'listing_title' => 'Deal race chickens', 'listing_version' => 1, 'product_name' => 'Chicken',
        ]);
        $i->forceFill(['reference' => 'PIN-9998-'.random_int(10000, 99999), 'listing_id' => $this->listing->id, 'shop_id' => $this->shop->id, 'buyer_id' => $buyer->id])->save();

        return $i;
    }

    private function deals(): MarketplaceDealService
    {
        return app(MarketplaceDealService::class);
    }

    private function lifecycle(): MarketplaceDealLifecycle
    {
        return app(MarketplaceDealLifecycle::class);
    }

    private function dealFor(User $buyer): MarketplaceDeal
    {
        return $this->deals()->confirmOffer($buyer, $this->acceptedOffer($buyer)->id, [])[0];
    }

    // ------------------------------------------------------------------ door A

    public function test_simultaneous_confirmations_of_one_accepted_offer_create_exactly_one_deal(): void
    {
        $buyer = $this->buyer();
        $offer = $this->acceptedOffer($buyer);
        $results = $this->race(array_fill(0, 6, fn () => $this->deals()->confirmOffer($buyer, $offer->id, ['contact_phone' => '+2348055501234'])));

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));   // idempotent: every caller gets the deal
        $this->assertSame(1, MarketplaceDeal::count());
        $this->assertSame(1, MarketplaceDeal::where('offer_id', $offer->id)->count());
        $this->assertSame(1, MarketplaceDealEvent::where('action', 'created')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'marketplace.deal_created')->count());
    }

    public function test_the_database_itself_refuses_a_second_deal_from_one_source(): void
    {
        $buyer = $this->buyer();
        $offer = $this->acceptedOffer($buyer);
        $deal = $this->deals()->confirmOffer($buyer, $offer->id, [])[0];

        $dup = $deal->replicate(['reference', 'status']);
        $dup->forceFill(['reference' => 'DEL-9998-00001', 'status' => DealStatus::Accepted]);
        try {
            $dup->save();
            $this->fail('a second deal for the same offer must be refused by the database');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Duplicate entry', $e->getMessage());
        }

        $noSource = $deal->replicate(['reference', 'offer_id']);
        $noSource->forceFill(['reference' => 'DEL-9998-00002', 'offer_id' => null, 'confirmation_id' => null, 'status' => DealStatus::Accepted]);
        try {
            $noSource->save();
            $this->fail('a deal with no source must be refused by the CHECK constraint');
        } catch (QueryException $e) {
            $this->assertStringContainsString('marketplace_deals_one_source', $e->getMessage());
        }
        $this->assertSame(1, MarketplaceDeal::count());
    }

    public function test_different_buyers_confirming_together_get_distinct_deals_and_references(): void
    {
        $jobs = [];
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $buyer = $this->buyer($name);
            $offer = $this->acceptedOffer($buyer);
            $jobs[] = fn () => $this->deals()->confirmOffer($buyer, $offer->id, []);
        }
        $results = $this->race($jobs);

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $refs = MarketplaceDeal::pluck('reference');
        $this->assertCount(4, $refs);
        $this->assertCount(4, $refs->unique());
    }

    // ------------------------------------------------------------------ door B

    public function test_simultaneous_identical_seller_confirmations_leave_one_open_confirmation(): void
    {
        $intent = $this->intent($this->buyer());
        $job = fn (User $by) => fn () => $this->deals()->confirmIntent($by, $this->shop->id, $intent->id, ['fulfilment_method' => 'pickup']);
        $results = $this->race([$job($this->seller), $job($this->manager), $job($this->seller), $job($this->manager)]);

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $this->assertSame(1, MarketplaceDealConfirmation::where('intent_id', $intent->id)->count());
        $this->assertSame(1, MarketplaceDealConfirmation::where('intent_id', $intent->id)->where('open_slot', 'O')->count());
    }

    public function test_simultaneous_confirmations_on_different_terms_have_exactly_one_winner(): void
    {
        $intent = $this->intent($this->buyer());
        MarketplaceListing::whereKey($this->listing->id)->update(['fulfilment' => 'both', 'delivery_charge' => 'agreed_separately', 'delivery_coverage' => json_encode(['Oyo'])]);
        $as = fn (User $by, string $method) => fn () => $this->deals()->confirmIntent($by, $this->shop->id, $intent->id, ['fulfilment_method' => $method]);
        $results = $this->race([$as($this->seller, 'pickup'), $as($this->manager, 'seller_delivery'), $as($this->seller, 'pickup'), $as($this->manager, 'seller_delivery')]);

        $open = MarketplaceDealConfirmation::where('intent_id', $intent->id)->where('status', 'awaiting_buyer')->get();
        $this->assertCount(1, $open, json_encode($results));
        $winner = $open->first()->fulfilment_method;
        foreach ($results as $i => $result) {
            $method = $i % 2 === 0 ? 'pickup' : 'seller_delivery';
            $this->assertSame($method === $winner ? 'ok' : 'error:confirmation_pending', $result, "job $i: ".json_encode($results));
        }
    }

    public function test_simultaneous_buyer_confirmations_of_one_seller_confirmation_create_one_deal(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer);
        $confirmation = $this->deals()->confirmIntent($this->seller, $this->shop->id, $intent->id, ['fulfilment_method' => 'pickup'])[0];
        $results = $this->race(array_fill(0, 6, fn () => $this->deals()->acceptConfirmation($buyer, $confirmation->id, [])));

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $this->assertSame(1, MarketplaceDeal::count());
        $this->assertSame(ConfirmationStatus::Converted, $confirmation->fresh()->status);
        $this->assertNull($confirmation->fresh()->open_slot);
        $this->assertNotNull($intent->fresh()->converted_at);
    }

    public function test_a_buyer_changing_the_request_races_the_buyers_confirmation_without_ever_leaving_a_mismatch(): void
    {
        $buyer = $this->buyer();
        $intent = $this->intent($buyer, '10');
        $confirmation = $this->deals()->confirmIntent($this->seller, $this->shop->id, $intent->id, ['fulfilment_method' => 'pickup'])[0];

        $results = $this->race([
            fn () => $this->deals()->acceptConfirmation($buyer, $confirmation->id, []),
            fn () => app(MarketplaceOfferService::class)->recordIntent($buyer, $this->listing->slug, '25'),
        ]);

        $this->assertSame('ok', $results[1], json_encode($results));
        $deal = MarketplaceDeal::first();
        if ($deal) {
            // the confirmation won: the deal holds the quantity the seller confirmed, never the refreshed one
            $this->assertSame('10', rtrim(rtrim((string) $deal->quantity, '0'), '.'));
            $this->assertSame('ok', $results[0]);
        } else {
            // the refresh won: the confirmation is voided and the buyer's attempt was refused
            $this->assertSame('voided', $confirmation->fresh()->status->value);
            $this->assertSame('error:confirmation_voided', $results[0]);
        }
        $this->assertSame(0, MarketplaceDealConfirmation::where('status', 'awaiting_buyer')->count());
    }

    // ------------------------------------------------------------------ lifecycle

    public function test_both_sides_completing_at_the_same_moment_complete_the_deal_exactly_once(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealFor($buyer);
        $results = $this->race([
            fn () => $this->lifecycle()->complete($buyer, 'buyer', null, $deal->id),
            fn () => $this->lifecycle()->complete($this->seller, 'seller', $this->shop->id, $deal->id),
            fn () => $this->lifecycle()->complete($this->manager, 'seller', $this->shop->id, $deal->id),
            fn () => $this->lifecycle()->complete($buyer, 'buyer', null, $deal->id),
        ]);

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $fresh = $deal->fresh();
        $this->assertSame(DealStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->buyer_completed_at);
        $this->assertNotNull($fresh->seller_completed_at);
        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $deal->id)->where('action', 'completed')->count());
        $this->assertSame(2, MarketplaceDealEvent::where('deal_id', $deal->id)->where('action', 'completion_confirmed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'marketplace.deal_completed')->count());
    }

    public function test_cancelling_races_completing_with_exactly_one_terminal_outcome(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealFor($buyer);
        $this->lifecycle()->complete($this->seller, 'seller', $this->shop->id, $deal->id);   // the seller already reported; the buyer's completion would finish it

        $results = $this->race([
            fn () => $this->lifecycle()->complete($buyer, 'buyer', null, $deal->id),
            fn () => $this->lifecycle()->cancel($this->seller, 'seller', $this->shop->id, $deal->id, 'other', null),
        ]);

        $fresh = $deal->fresh();
        $this->assertContains($fresh->status, [DealStatus::Completed, DealStatus::Cancelled], json_encode($results));
        if ($fresh->status === DealStatus::Completed) {
            $this->assertSame(['ok', 'error:deal_not_open'], $results);
            $this->assertNull($fresh->cancelled_at);
        } else {
            $this->assertSame(['error:deal_not_open', 'ok'], $results);
            $this->assertNull($fresh->completed_at);
            $this->assertNull($fresh->buyer_completed_at);
        }
        $terminal = MarketplaceDealEvent::where('deal_id', $deal->id)->whereIn('action', ['completed', 'cancelled'])->count();
        $this->assertSame(1, $terminal);
    }

    public function test_simultaneous_cancellations_cancel_once(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealFor($buyer);
        $results = $this->race([
            fn () => $this->lifecycle()->cancel($buyer, 'buyer', null, $deal->id, 'changed_mind', null),
            fn () => $this->lifecycle()->cancel($this->seller, 'seller', $this->shop->id, $deal->id, 'other', null),
            fn () => $this->lifecycle()->cancel($this->manager, 'seller', $this->shop->id, $deal->id, 'other', null),
        ]);

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $deal->id)->where('action', 'cancelled')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'marketplace.deal_cancelled')->count());
    }

    public function test_simultaneous_identical_reports_leave_one_report(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealFor($buyer);
        $results = $this->race(array_fill(0, 5, fn () => $this->lifecycle()->report($buyer, 'buyer', null, $deal->id, 'deal', 'no_show', 'Did not show.')));

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $this->assertSame(1, MarketplaceDealReport::count());
        $this->assertSame(1, MarketplaceDealEvent::where('deal_id', $deal->id)->where('action', 'reported')->count());
        $this->assertSame(DealStatus::Accepted, $deal->fresh()->status);
    }

    public function test_reports_by_different_parties_get_distinct_references(): void
    {
        $buyer = $this->buyer();
        $deal = $this->dealFor($buyer);
        $results = $this->race([
            fn () => $this->lifecycle()->report($buyer, 'buyer', null, $deal->id, 'other_party', 'no_show', null),
            fn () => $this->lifecycle()->report($this->seller, 'seller', $this->shop->id, $deal->id, 'other_party', 'terms_changed', null),
            fn () => $this->lifecycle()->report($this->manager, 'seller', $this->shop->id, $deal->id, 'deal', 'other', null),
        ]);

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $refs = MarketplaceDealReport::pluck('reference');
        $this->assertCount(3, $refs);
        $this->assertCount(3, $refs->unique());
    }
}
