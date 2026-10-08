<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\ShopRole;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceOfferEvent;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\Species;
use App\Models\Unit;
use App\Models\User;
use App\Services\Marketplace\MarketplaceOfferService;
use App\Support\Api\ApiHttpException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REAL concurrency: several operating-system processes (pcntl_fork), each on its OWN database connection, released together by a barrier and committing
 * for real. The other marketplace tests run inside one wrapped transaction and cannot show races, so this class commits its fixtures and removes them in
 * tearDown. It never uses RefreshDatabase.
 */
class MarketplaceOfferConcurrencyTest extends TestCase
{
    private string $dir;

    private MarketplaceShop $shop;

    private MarketplaceListing $listing;

    private User $seller;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency tests.');
        }
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);   // idempotent; makes the class runnable on its own
        $this->dir = sys_get_temp_dir().'/offer-race-'.Str::random(8);
        mkdir($this->dir);

        $this->seller = User::factory()->create(['name' => 'Race Seller']);
        $this->shop = MarketplaceShop::create([
            'name' => 'Race Shop', 'slug' => 'race-shop-'.Str::lower(Str::random(6)), 'reference' => 'SHP-9999-'.random_int(10000, 99999), 'seller_type' => 'business', 'categories' => [],
            'created_by' => $this->seller->id, 'status' => ShopStatus::Active, 'verification_status' => 'unverified', 'state' => 'Oyo', 'city' => 'Ibadan', 'country_code' => 'NG',
        ]);
        MarketplaceShopMember::create(['shop_id' => $this->shop->id, 'user_id' => $this->seller->id, 'role' => ShopRole::Owner, 'added_by' => $this->seller->id]);
        $this->listing = new MarketplaceListing([
            'title' => 'Race chickens', 'product_kind' => 'livestock', 'species_id' => Species::where('code', 'chicken')->value('id'), 'unit_id' => Unit::where('code', 'head')->value('id'),
            'unit_price' => '8000.00', 'currency' => 'NGN', 'available_quantity' => '100', 'min_order_quantity' => '1', 'negotiable' => true, 'fulfilment' => 'pickup',
            'state' => 'Oyo', 'city' => 'Ibadan',
        ]);
        $this->listing->forceFill(['reference' => 'LST-9999-'.random_int(10000, 99999), 'slug' => 'race-chickens-'.Str::lower(Str::random(6)), 'shop_id' => $this->shop->id,
            'created_by' => $this->seller->id, 'status' => ListingStatus::Published, 'published_at' => now(), 'version' => 1])->save();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            DB::reconnect();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['marketplace_offer_events', 'marketplace_offers', 'marketplace_purchase_intents', 'marketplace_listing_events', 'marketplace_listing_images', 'marketplace_listings',
                'marketplace_shop_members', 'marketplace_shops', 'audit_logs', 'users'] as $table) {
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
     * Runs every closure in its own process, all released at the same moment, and returns their results in order. A result is `ok` or `error:<code>`.
     *
     * @param  list<callable(): mixed>  $jobs
     * @return list<string>
     */
    private function race(array $jobs): array
    {
        DB::disconnect();   // no live socket may exist at fork time, or a child closing it would cut the parent's connection
        $pids = [];
        foreach ($jobs as $i => $job) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork failed');
            }
            if ($pid === 0) {
                // Nothing in a child may escape: an exception reaching PHPUnit here would make the child carry on as if it were the test runner.
                try {
                    $out = 'error:crash';
                    try {
                        DB::reconnect();
                        DB::select('SELECT 1');                                   // own connection, opened before the barrier
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
                    posix_kill(getmypid(), SIGKILL);   // skip shutdown handlers and destructors: nothing of the parent's may be touched
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
        // waitpid can return early (an ignored SIGCHLD reaps children itself), so the result files are the real signal that every child has finished
        $deadline = microtime(true) + 30;
        while (count(glob("{$this->dir}/result-*")) < count($jobs) && microtime(true) < $deadline) {
            usleep(1000);
        }
        DB::reconnect();

        return array_map(fn ($i) => (string) @file_get_contents("{$this->dir}/result-$i"), array_keys($jobs));
    }

    private function buyer(string $name = 'Racer'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    /** A consumed attempt that is already settled (the seller rejected it). */
    private function rejectedAttempt(User $buyer, int $attempt): void
    {
        $o = new MarketplaceOffer([
            'quantity' => '10', 'unit_price' => '7000', 'total_amount' => '70000', 'currency' => 'NGN', 'listing_title' => 'Race chickens', 'listing_version' => 1,
            'listed_unit_price' => '8000', 'unit_id' => $this->listing->unit_id, 'unit_code' => 'head', 'product_kind' => 'livestock', 'species_id' => $this->listing->species_id,
            'product_name' => 'Chicken', 'listing_available_quantity' => '100', 'listing_min_order_quantity' => '1', 'expires_at' => now()->addDay(),
        ]);
        $o->forceFill(['reference' => 'OFR-9999-'.str_pad((string) (90000 + $attempt + random_int(0, 999)), 5, '0', STR_PAD_LEFT), 'listing_id' => $this->listing->id, 'shop_id' => $this->shop->id,
            'buyer_id' => $buyer->id, 'attempt_no' => $attempt, 'status' => OfferStatus::Rejected, 'pending_slot' => null])->save();
    }

    private function submitJob(User $buyer): \Closure
    {
        return fn () => app(MarketplaceOfferService::class)->submit($buyer, $this->listing->slug, ['quantity' => '10', 'unit_price' => '7000']);
    }

    public function test_simultaneous_submissions_by_one_buyer_create_exactly_one_pending_offer(): void
    {
        $buyer = $this->buyer();
        $results = $this->race(array_fill(0, 6, $this->submitJob($buyer)));

        $this->assertSame(1, count(array_keys($results, 'ok')), json_encode($results));
        $this->assertSame(5, count(array_keys($results, 'error:offer_pending')), json_encode($results));
        $this->assertSame(1, MarketplaceOffer::where('buyer_id', $buyer->id)->count());
        $this->assertSame(1, MarketplaceOffer::where('buyer_id', $buyer->id)->where('status', 'pending')->where('pending_slot', 'P')->count());
        $this->assertSame(1, MarketplaceOfferEvent::count());
    }

    public function test_simultaneous_submissions_can_never_exceed_the_attempt_limit(): void
    {
        $buyer = $this->buyer();
        $this->rejectedAttempt($buyer, 1);
        $this->rejectedAttempt($buyer, 2);   // limit is 3: exactly one attempt is left
        $results = $this->race(array_fill(0, 5, $this->submitJob($buyer)));

        $this->assertSame(1, count(array_keys($results, 'ok')), json_encode($results));
        $this->assertSame(3, MarketplaceOffer::where('buyer_id', $buyer->id)->count());
        $this->assertSame([1, 2, 3], MarketplaceOffer::where('buyer_id', $buyer->id)->orderBy('attempt_no')->pluck('attempt_no')->all());

        $exhausted = $this->buyer('Exhausted');
        foreach ([1, 2, 3] as $n) {
            $this->rejectedAttempt($exhausted, $n);
        }
        $results = $this->race(array_fill(0, 4, $this->submitJob($exhausted)));
        $this->assertSame(['error:offer_limit_reached'], array_values(array_unique($results)), json_encode($results));
        $this->assertSame(3, MarketplaceOffer::where('buyer_id', $exhausted->id)->count());
    }

    public function test_different_buyers_do_not_block_each_other_and_references_stay_unique(): void
    {
        $buyers = [$this->buyer('A'), $this->buyer('B'), $this->buyer('C'), $this->buyer('D')];
        $results = $this->race(array_map(fn ($b) => $this->submitJob($b), $buyers));

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $refs = MarketplaceOffer::pluck('reference');
        $this->assertCount(4, $refs);
        $this->assertCount(4, $refs->unique());
    }

    public function test_simultaneous_seller_decisions_have_exactly_one_winner(): void
    {
        $manager = User::factory()->create();
        MarketplaceShopMember::create(['shop_id' => $this->shop->id, 'user_id' => $manager->id, 'role' => ShopRole::Manager, 'added_by' => $this->seller->id]);
        $offer = app(MarketplaceOfferService::class)->submit($this->buyer(), $this->listing->slug, ['quantity' => '10', 'unit_price' => '7000']);

        $accept = fn (User $by) => fn () => app(MarketplaceOfferService::class)->accept($by, $this->shop->id, $offer->id);
        $reject = fn (User $by) => fn () => app(MarketplaceOfferService::class)->reject($by, $this->shop->id, $offer->id);
        $results = $this->race([$accept($this->seller), $reject($manager), $accept($manager), $reject($this->seller)]);

        $final = $offer->fresh();
        $this->assertContains($final->status, [OfferStatus::Accepted, OfferStatus::Rejected]);
        $winnerWord = $final->status === OfferStatus::Accepted ? 'accept' : 'reject';
        // jobs 0 and 2 accept, 1 and 3 reject: a repeat of the winning decision is an idempotent success, the opposite decision is refused
        foreach ($results as $i => $result) {
            $word = $i % 2 === 0 ? 'accept' : 'reject';
            $this->assertSame($word === $winnerWord ? 'ok' : 'error:offer_not_pending', $result, "job $i: ".json_encode($results));
        }
        $this->assertSame(1, MarketplaceOfferEvent::where('offer_id', $offer->id)->whereIn('action', ['accepted', 'rejected'])->count());
        $this->assertNull($final->pending_slot);
    }
}
