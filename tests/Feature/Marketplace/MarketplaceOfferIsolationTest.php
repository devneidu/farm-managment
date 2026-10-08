<?php

namespace Tests\Feature\Marketplace;

use App\Models\AuditLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOfferEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Negotiation records interest and agreement in principle only: no stock, sale, finance or payment record may appear, and the hourly sweep must be safe to repeat. */
class MarketplaceOfferIsolationTest extends OfferTestCase
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

    public function test_the_whole_negotiation_lifecycle_only_writes_marketplace_and_audit_tables(): void
    {
        [$other, $third, $fourth] = User::factory()->count(3)->create()->all();   // created first so the snapshot only sees negotiation writes
        $before = $this->rowCounts();

        $accepted = $this->offerId();
        $this->respond('accept', $accepted)->assertOk();
        $rejected = $this->offerId([], $other);
        $this->respond('reject', $rejected)->assertOk();
        $voided = $this->offerId([], $third);
        $this->editListing(['unit_price' => '8500', 'version' => $this->version()])->assertOk();   // voids the pending offer
        $this->assertSame('voided', $this->stored($voided)->status->value);
        $expiring = $this->offerId([], $fourth);
        Carbon::setTestNow(now()->addHours(49));
        Artisan::call('marketplace:expire-offers');
        $this->assertSame('expired', $this->stored($expiring)->status->value);
        $this->signInAs($this->buyer)->postJson(self::SELLER."/listings/{$this->slug}/purchase-intent", ['quantity' => '10'])->assertCreated();

        $changed = array_keys(array_filter($this->rowCounts(), fn (int $n, string $t) => $n !== $before[$t], ARRAY_FILTER_USE_BOTH));
        sort($changed);
        $this->assertSame(
            ['audit_logs', 'marketplace_offer_events', 'marketplace_offers', 'marketplace_purchase_intents'],
            $changed,
            'negotiation must not touch inventory, sales, invoices, payments, finance, contacts or any other table',
        );
    }

    public function test_the_expiry_sweep_is_scheduled_hourly(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $e) => str_contains($e->command, 'marketplace:expire-offers'));

        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
    }

    public function test_repeated_sweeps_never_duplicate_expiry_events_or_audit_rows(): void
    {
        $id = $this->offerId();
        Carbon::setTestNow(now()->addHours(49));

        foreach (range(1, 4) as $_) {
            $this->assertSame(0, Artisan::call('marketplace:expire-offers'));
        }
        $this->signInAs($this->buyer)->getJson(self::SELLER."/my/offers/$id")->assertOk()->assertJsonPath('data.status', 'expired');   // a read after the sweep adds nothing either
        $this->respond('accept', $id)->assertStatus(409)->assertJsonPath('code', 'offer_expired');                                        // nor does a write on the lapsed offer

        $this->assertSame(1, MarketplaceOfferEvent::where('offer_id', $id)->where('action', 'expired')->count());
        $this->assertSame(1, AuditLog::where('action', 'marketplace.offer_expired')->count());
        $this->assertNull($this->stored($id)->pending_slot);
    }

    private function version(): int
    {
        return (int) MarketplaceListing::findOrFail($this->listingId)->version;
    }
}
