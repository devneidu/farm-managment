<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\ShopRole;
use App\Enums\ShopStatus;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSellerPlan;
use App\Models\MarketplaceServicePayment;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\MarketplaceShopSubscription;
use App\Models\Species;
use App\Models\Unit;
use App\Models\User;
use App\Services\Marketplace\MarketplaceListingService;
use App\Services\Marketplace\ServicePaymentSettler;
use App\Support\Api\ApiHttpException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REAL concurrency (pcntl_fork, one database connection per process, a start barrier, real commits) for the money paths: one payment settled by many
 * processes at once, simultaneous renewals of one shop, and simultaneous publishes racing for the last free place. Fixtures are committed and removed in
 * tearDown; this class never uses RefreshDatabase.
 */
class MarketplaceMonetisationConcurrencyTest extends TestCase
{
    private string $dir;

    private MarketplaceShop $shop;

    private User $seller;

    private MarketplaceSellerPlan $plus;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the concurrency tests.');
        }
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
        $this->dir = sys_get_temp_dir().'/pay-race-'.Str::random(8);
        mkdir($this->dir);
        config(['services.paystack.secret_key' => 'sk_test_race']);
        Http::fake(['*/transaction/verify/*' => fn (Request $r) => Http::response(['status' => true, 'data' => ['status' => 'success', 'reference' => urldecode(basename(parse_url($r->url(), PHP_URL_PATH))), 'amount' => 500000, 'currency' => 'NGN']])]);

        $this->seller = User::factory()->create(['name' => 'Pay Racer']);
        $this->shop = MarketplaceShop::create([
            'name' => 'Pay Race Shop', 'slug' => 'pay-race-'.Str::lower(Str::random(6)), 'reference' => 'SHP-9998-'.random_int(10000, 99999), 'seller_type' => 'business', 'categories' => [],
            'created_by' => $this->seller->id, 'status' => ShopStatus::Active, 'verification_status' => 'unverified', 'state' => 'Oyo', 'city' => 'Ibadan', 'country_code' => 'NG',
        ]);
        MarketplaceShopMember::create(['shop_id' => $this->shop->id, 'user_id' => $this->seller->id, 'role' => ShopRole::Owner, 'added_by' => $this->seller->id]);
        $this->plus = MarketplaceSellerPlan::where('code', 'seller_plus')->firstOrFail();
        $this->plus->update(['listing_limit' => 25]);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            DB::reconnect();
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['marketplace_shop_subscriptions', 'marketplace_promotions', 'marketplace_service_payments', 'marketplace_listing_events', 'marketplace_listings',
                'marketplace_shop_members', 'marketplace_shops', 'audit_logs', 'users'] as $table) {
                DB::table($table)->delete();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->plus?->update(['listing_limit' => null]);
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

    private function payment(int $n): MarketplaceServicePayment
    {
        $p = new MarketplaceServicePayment([
            'shop_id' => $this->shop->id, 'user_id' => $this->seller->id, 'purpose' => 'subscription', 'provider' => 'paystack', 'amount' => '5000.00', 'currency' => 'NGN', 'status' => 'pending',
            'plan_id' => $this->plus->id, 'interval_days' => 30, 'subject_label' => 'Seller Plus - 30 days',
        ]);
        $p->forceFill(['reference' => 'MSP-9998-'.str_pad((string) (10000 + $n), 5, '0', STR_PAD_LEFT)])->save();

        return $p;
    }

    private function draft(int $n): MarketplaceListing
    {
        $l = new MarketplaceListing([
            'title' => "Race chickens $n", 'product_kind' => 'livestock', 'species_id' => Species::where('code', 'chicken')->value('id'), 'unit_id' => Unit::where('code', 'head')->value('id'),
            'unit_price' => '8000.00', 'currency' => 'NGN', 'available_quantity' => '100', 'min_order_quantity' => '1', 'negotiable' => false, 'fulfilment' => 'pickup', 'state' => 'Oyo', 'city' => 'Ibadan',
        ]);
        $l->forceFill(['reference' => 'LST-9998-'.str_pad((string) (10000 + $n), 5, '0', STR_PAD_LEFT), 'slug' => 'pay-race-'.$n.'-'.Str::lower(Str::random(6)), 'shop_id' => $this->shop->id,
            'created_by' => $this->seller->id, 'status' => ListingStatus::Draft, 'version' => 1])->save();

        return $l;
    }

    public function test_many_processes_settling_one_payment_grant_it_exactly_once(): void
    {
        $p = $this->payment(1);
        $results = $this->race(array_fill(0, 6, fn () => app(ServicePaymentSettler::class)->settle($p->reference)));

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $this->assertSame(1, MarketplaceShopSubscription::where('shop_id', $this->shop->id)->count());
        $this->assertNotNull($p->refresh()->settled_at);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'marketplace.subscription_activated')->count());
    }

    public function test_simultaneous_renewals_chain_without_overlap(): void
    {
        $payments = [$this->payment(1), $this->payment(2), $this->payment(3), $this->payment(4)];
        $results = $this->race(array_map(fn ($p) => fn () => app(ServicePaymentSettler::class)->settle($p->reference), $payments));

        $this->assertSame(['ok'], array_values(array_unique($results)), json_encode($results));
        $periods = MarketplaceShopSubscription::where('shop_id', $this->shop->id)->orderBy('starts_at')->get();
        $this->assertCount(4, $periods);
        foreach ($periods as $i => $period) {
            if ($i > 0) {
                $this->assertTrue($period->starts_at->equalTo($periods[$i - 1]->ends_at), 'each renewal starts exactly where the previous period ends');
            }
        }
        $this->assertTrue($periods->last()->ends_at->equalTo($periods->first()->starts_at->copy()->addDays(120)));
    }

    public function test_simultaneous_publishes_cannot_exceed_the_limit(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $l = $this->draft($i);
            $l->forceFill(['status' => ListingStatus::Published, 'published_at' => now()])->save();   // 8 of the free 10 are taken
        }
        $drafts = [$this->draft(11), $this->draft(12), $this->draft(13), $this->draft(14), $this->draft(15)];
        $results = $this->race(array_map(fn ($l) => fn () => app(MarketplaceListingService::class)->publish($this->seller, $this->shop->id, $l->id, null), $drafts));

        $this->assertSame(2, count(array_keys($results, 'ok')), json_encode($results));
        $this->assertSame(3, count(array_keys($results, 'error:listing_limit_reached')), json_encode($results));
        $this->assertSame(10, MarketplaceListing::where('shop_id', $this->shop->id)->where('status', 'published')->count());
    }
}
