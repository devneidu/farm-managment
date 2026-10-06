<?php

namespace Tests\Feature\Dashboard;

use App\Enums\FarmRole;
use App\Models\CropType;
use App\Models\OperationType;
use App\Models\Species;
use App\Models\StorageLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Team\TeamTestCase;

/**
 * The fixed clock is 2026-10-14 11:00 UTC = 12:00 in Africa/Lagos, so "today" is unambiguous; the boundary tests move it to 23:30 UTC
 * (00:30 the next day in Lagos) on purpose.
 */
class DashboardTest extends TeamTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-pro');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 11:00:00', 'UTC'));
        $this->signInAs($this->owner);
    }

    // ------------------------------------------------------------------ helpers

    private function at(int $hoursAgo = 1): string
    {
        return now()->utc()->subHours($hoursAgo)->format('Y-m-d\TH:i:s\Z');
    }

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function today(): string
    {
        return now('Africa/Lagos')->toDateString();
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today(), 'UTC')->addDays($offset)->toDateString();
    }

    private function layers(int $population = 100, array $extra = []): string
    {
        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'livestock', 'name' => 'Layers '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'poultry')->firstOrFail()->id,
            'species_id' => Species::where('code', 'chicken')->firstOrFail()->id, 'initial_population' => $population, 'start_date' => '2026-01-01'], $extra))->assertCreated()->json('data.id');
    }

    private function yam(array $extra = []): string
    {
        return $this->postJson('/api/v1/production-cycles', array_replace(['kind' => 'crop', 'name' => 'Yam '.Str::random(4), 'operation_type_id' => OperationType::where('code', 'crops')->firstOrFail()->id,
            'crop_type_id' => CropType::where('code', 'yam')->firstOrFail()->id, 'planting_material_type' => 'tuber', 'planting_unit_type' => 'heap', 'initial_planting_units' => 500, 'planting_date' => '2026-01-10'], $extra))->assertCreated()->json('data.id');
    }

    private function record(string $cycle, string $type, array $details, int $hoursAgo = 2)
    {
        return $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => $type, 'recorded_at' => $this->at($hoursAgo), 'idempotency_key' => $this->key(), 'details' => $details]);
    }

    private function mortality(string $cycle, int $quantity, int $hoursAgo = 2): array
    {
        return $this->record($cycle, 'mortality', ['quantity' => $quantity, 'cause' => 'Disease'], $hoursAgo)->assertCreated()->json('data');
    }

    private function eggs(string $cycle, int $count, int $hoursAgo = 2): void
    {
        // Eggs are stocked in the farm's own egg item; once a farm has several stores the store is chosen (here: its oldest).
        $store = StorageLocation::where('farm_id', $this->farm->id)->where('is_active', true)->orderBy('id')->value('id');
        $this->record($cycle, 'egg_collection', ['components' => [['quantity' => $count, 'unit' => 'piece']]] + ($store ? ['inventory' => ['storage_location_id' => $store]] : []), $hoursAgo)->assertCreated();
    }

    private function harvest(string $cycle, string $quantity, string $unit = 'kg', int $hoursAgo = 2): array
    {
        return $this->record($cycle, 'crop_harvest', ['components' => [['quantity' => $quantity, 'unit' => $unit]]], $hoursAgo)->assertCreated()->json('data');
    }

    private function task(array $extra = [])
    {
        return $this->postJson('/api/v1/tasks', array_replace(['title' => 'Check drinkers', 'category' => 'feeding_watering', 'due_date' => $this->today(), 'idempotency_key' => $this->key()], $extra));
    }

    private function dash(): array
    {
        return $this->getJson('/api/v1/dashboard')->assertOk()->json('data');
    }

    private function kpis(array $data): array
    {
        return array_column($data['kpis'], null, 'code');
    }

    private function store(): string
    {
        return $this->postJson('/api/v1/storage-locations', ['name' => 'Store '.Str::random(5), 'type' => 'store'])->assertCreated()->json('data.id');
    }

    private function item(string $category = 'produce', string $unit = 'piece', array $extra = []): string
    {
        return $this->postJson('/api/v1/inventory/items', array_replace(['name' => ucfirst($category).' '.Str::random(6), 'category' => $category, 'stock_unit' => $unit], $extra))->assertCreated()->json('data.id');
    }

    private function receive(string $item, string $loc, string $qty, string $unit = 'piece', array $extra = []): array
    {
        return $this->postJson('/api/v1/inventory/stock-in', array_replace(['inventory_item_id' => $item, 'storage_location_id' => $loc, 'reason' => 'opening_balance', 'components' => [['quantity' => $qty, 'unit' => $unit]],
            'recorded_at' => $this->at(30), 'idempotency_key' => $this->key()], $extra))->assertCreated()->json('data');
    }

    private function customer(): string
    {
        return $this->postJson('/api/v1/contacts', ['name' => 'Buyer '.Str::random(4), 'kind' => 'business', 'roles' => ['customer']])->assertCreated()->json('data.id');
    }

    /** A sale of $amount (eggs out of stock) with an invoice: [sale, invoice]. */
    private function invoicedSale(string $amount, array $invoice = [], int $saleHoursAgo = 3, int $stockHoursAgo = 30): array
    {
        $eggs = $this->item();
        $loc = $this->store();
        $this->receive($eggs, $loc, '1000', 'piece', ['recorded_at' => $this->at($stockHoursAgo)]);
        $sale = $this->postJson('/api/v1/sales', ['recorded_at' => $this->at($saleHoursAgo), 'idempotency_key' => $this->key(), 'contact_id' => $this->customer(),
            'items' => [['kind' => 'stock', 'inventory_item_id' => $eggs, 'storage_location_id' => $loc, 'components' => [['quantity' => '100', 'unit' => 'piece']], 'amount' => $amount]],
            'invoice' => $invoice ?: (object) []])->assertCreated()->json('data');

        return [$sale, $sale['invoice']];
    }

    private function pay(string $invoice, string $amount)
    {
        return $this->postJson('/api/v1/invoices/'.$invoice.'/payments', ['amount' => $amount, 'method' => 'cash', 'received_on' => $this->today(), 'idempotency_key' => $this->key()]);
    }

    private function category(string $code, string $direction): string
    {
        foreach ($this->getJson('/api/v1/finance/categories?direction='.$direction)->assertOk()->json('data') as $row) {
            if ($row['code'] === $code) {
                return $row['id'];
            }
        }
        $this->fail("Category $code missing");
    }

    private function income(string $amount): void
    {
        $this->postJson('/api/v1/income', ['finance_category_id' => $this->category('egg_sales', 'income'), 'amount' => $amount, 'occurred_on' => $this->today(), 'idempotency_key' => $this->key()])->assertCreated();
    }

    private function expense(string $amount): void
    {
        $this->postJson('/api/v1/expenses', ['finance_category_id' => $this->category('transport', 'expense'), 'amount' => $amount, 'occurred_on' => $this->today(), 'idempotency_key' => $this->key()])->assertCreated();
    }

    // ------------------------------------------------------------------ structure, empty and operation-aware

    public function test_new_farm_gets_an_empty_state_and_no_irrelevant_cards(): void
    {
        $d = $this->dash();

        foreach (['farm', 'viewer', 'operations', 'sections', 'priority', 'kpis', 'work', 'calendar', 'quick_record', 'production', 'insights', 'recent_activity', 'quick_add', 'empty_state'] as $block) {
            $this->assertArrayHasKey($block, $d);
        }
        $this->assertSame($this->farm->id, $d['farm']['id']);
        $this->assertSame('Africa/Lagos', $d['farm']['timezone']);
        $this->assertSame($this->today(), $d['farm']['today']);
        $this->assertSame('owner', $d['viewer']['role']);
        $this->assertSame([], $d['kpis'], 'a farm with no operations, stock or money has no cards to show');
        $this->assertFalse($d['operations']['configured']);
        $this->assertFalse($d['operations']['relevant']['livestock']);
        $this->assertFalse($d['operations']['relevant']['crop']);
        $this->assertSame(0, $d['production']['total_active']);
        $this->assertSame(['overdue' => 0, 'due_today' => 0, 'upcoming' => 0, 'upcoming_7d' => 0, 'completed_today' => 0], $d['work']['counts']);
        $this->assertCount(7, $d['calendar']['days']);
        $this->assertSame(0, $d['insights']['total']);
        $this->assertSame([], $d['recent_activity']);
        $this->assertSame([], $d['quick_record']);
        $this->assertFalse($d['empty_state']['has_active_production']);
        $this->assertContains('production_cycle', array_column($d['empty_state']['suggested_actions'], 'code'));
        // Nothing health/breeding-specific is offered before any livestock exists.
        $this->assertNotContains('health_record', array_column($d['quick_add'], 'code'));
        $this->assertNotContains('breeding_project', array_column($d['quick_add'], 'code'));
        $this->assertNotNull($this->getJson('/api/v1/insights')->assertOk()->json('meta.by_severity'));
    }

    public function test_requires_authentication_and_ignores_a_client_supplied_farm(): void
    {
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->layers(40);
        $this->signInAs($this->owner);

        $this->assertSame(0, $this->getJson('/api/v1/dashboard?farm_id='.$otherOwner->currentFarm()->id)->assertOk()->json('data.production.total_active'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/insights')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/calendar')->assertUnauthorized();
    }

    public function test_livestock_only_farm_sees_livestock_cards_and_no_crop_cards(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 5);
        $this->eggs($cycle, 240);
        $this->eggs($cycle, 60, 3);

        $d = $this->dash();
        $kpis = $this->kpis($d);

        $this->assertTrue($d['operations']['relevant']['livestock']);
        $this->assertFalse($d['operations']['relevant']['crop']);
        $this->assertSame('95', $kpis['livestock_population']['value']);
        $this->assertSame('head', $kpis['livestock_population']['unit']);
        $this->assertSame(1, $kpis['livestock_population']['detail']['active_batches']);
        $this->assertSame('5', $kpis['mortality_7d']['value']);
        $this->assertSame('300', $kpis['eggs_today']['value']);
        $this->assertSame([['unit' => 'piece', 'quantity' => '300']], $kpis['eggs_today']['breakdown']);
        $this->assertArrayNotHasKey('active_crop_projects', $kpis);
        $this->assertArrayNotHasKey('harvest_30d', $kpis);
        $quick = array_column($d['quick_record'], 'type');
        $this->assertContains('mortality', $quick);
        $this->assertNotContains('crop_harvest', $quick);
        $this->assertNotContains('planting', $quick);
        $this->assertContains('health_record', array_column($d['quick_add'], 'code'));
        $this->assertFalse($d['empty_state'] !== null);
    }

    public function test_crop_only_farm_sees_crop_cards_and_never_eggs_or_mortality(): void
    {
        $crop = $this->yam();
        $this->harvest($crop, '500', 'g');
        $this->harvest($crop, '1', 'kg');

        $d = $this->dash();
        $kpis = $this->kpis($d);

        $this->assertFalse($d['operations']['relevant']['livestock']);
        $this->assertTrue($d['operations']['relevant']['crop']);
        $this->assertSame('1', $kpis['active_crop_projects']['value']);
        $this->assertCount(1, $kpis['harvest_30d']['breakdown'], 'one dimension, one unit - never mixed');
        $this->assertSame(['unit' => 'g', 'quantity' => '1500'], $kpis['harvest_30d']['breakdown'][0], 'exact decimal sum of 500 g and 1 kg in the normalised unit');
        foreach (['livestock_population', 'mortality_7d', 'eggs_today', 'active_withdrawals', 'active_breeding_projects'] as $code) {
            $this->assertArrayNotHasKey($code, $kpis, $code.' is not relevant to a crop-only farm');
        }
        $quick = array_column($d['quick_record'], 'type');
        $this->assertNotContains('mortality', $quick);
        $this->assertNotContains('egg_collection', $quick);
        $this->assertNotContains('feed_use', $quick);
        $this->assertNotEmpty(array_intersect(['planting', 'irrigation', 'crop_harvest', 'fertilizer_application'], $quick));
        $add = array_column($d['quick_add'], 'code');
        $this->assertNotContains('health_record', $add);
        $this->assertNotContains('breeding_project', $add);
        $project = $d['production']['items'][0];
        $this->assertSame('crop', $project['kind']);
        $this->assertNull($project['livestock']);
        $this->assertSame(500, $project['crop']['initial_planting_units']);
        $this->assertSame('1500', $project['crop']['harvest_30d'][0]['quantity']);
    }

    public function test_mixed_farm_sees_both_and_selected_operations_drive_relevance_before_any_production(): void
    {
        // Selecting only Crops makes crop content relevant even with no project yet, and keeps livestock cards away.
        $crops = OperationType::where('code', 'crops')->firstOrFail()->id;
        $this->putJson('/api/v1/farm/operations', ['operation_ids' => [$crops]])->assertOk();
        $d = $this->dash();
        $this->assertTrue($d['operations']['configured']);
        $this->assertSame(['crops'], array_column($d['operations']['selected'], 'code'));
        $this->assertTrue($d['operations']['relevant']['crop']);
        $this->assertFalse($d['operations']['relevant']['livestock']);
        $this->assertSame('0', $this->kpis($d)['active_crop_projects']['value']);
        $this->assertArrayNotHasKey('livestock_population', $this->kpis($d));

        // Real production is data-aware: a livestock batch makes livestock relevant even though only Crops was selected.
        $this->layers(50);
        $this->yam();
        $d = $this->dash();
        $kpis = $this->kpis($d);
        $this->assertTrue($d['operations']['relevant']['livestock'] && $d['operations']['relevant']['crop']);
        $this->assertSame('50', $kpis['livestock_population']['value']);
        $this->assertSame('1', $kpis['active_crop_projects']['value']);
        $this->assertSame(2, $d['production']['total_active']);
        $this->assertEqualsCanonicalizing(['livestock', 'crop'], array_column($d['production']['items'], 'kind'));
    }

    // ------------------------------------------------------------------ production and population

    public function test_active_production_uses_the_population_ledger_and_leaves_out_closed_cycles(): void
    {
        $keep = $this->layers(100, ['name' => 'Keeper flock']);
        $closed = $this->layers(30, ['name' => 'Closed flock']);
        $this->mortality($keep, 8);
        $this->postJson('/api/v1/production-cycles/'.$closed.'/close', ['reason' => 'Sold out', 'end_date' => $this->today()])->assertOk();

        $d = $this->dash();

        $this->assertSame(1, $d['production']['total_active']);
        $row = $d['production']['items'][0];
        $this->assertSame($keep, $row['id']);
        $this->assertSame(100, $row['livestock']['initial_population']);
        $this->assertSame(92, $row['livestock']['current_population']);
        $this->assertSame(8, $row['livestock']['deaths_7d']);
        $this->assertSame('head', $row['livestock']['population_unit']);
        $this->assertGreaterThan(0, $row['age_days']);
        $this->assertSame('92', $this->kpis($d)['livestock_population']['value']);
    }

    public function test_reversed_records_do_not_count_as_mortality_or_eggs_or_activity(): void
    {
        $cycle = $this->layers(100);
        $record = $this->mortality($cycle, 5);
        $this->assertSame('5', $this->kpis($this->dash())['mortality_7d']['value']);
        $this->assertSame(['operational_record', 'production_cycle'], collect($this->dash()['recent_activity'])->pluck('kind')->unique()->sort()->values()->all());

        $this->postJson('/api/v1/records/'.$record['id'].'/reverse', ['reason' => 'Entered twice', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();

        $d = $this->dash();
        $this->assertSame('0', $this->kpis($d)['mortality_7d']['value']);
        $this->assertSame('100', $this->kpis($d)['livestock_population']['value']);
        $this->assertSame(['production_cycle'], collect($d['recent_activity'])->pluck('kind')->unique()->all(), 'the reversed event and its correcting row both leave the feed');
        $this->assertSame([], array_column(array_filter($d['insights']['items'], fn ($i) => $i['code'] === 'mortality_threshold'), 'code'));
    }

    // ------------------------------------------------------------------ inventory

    public function test_low_stock_and_expiring_lots_are_cards_and_insights(): void
    {
        $feed = $this->item('feed', 'kg', ['name' => 'Layer mash', 'low_stock_threshold' => ['quantity' => '50', 'unit' => 'kg']]);
        $plenty = $this->item('feed', 'kg', ['name' => 'Maize', 'low_stock_threshold' => ['quantity' => '10', 'unit' => 'kg']]);
        $loc = $this->store();
        $this->receive($feed, $loc, '40', 'kg');
        $this->receive($plenty, $loc, '500', 'kg');
        $empty = $this->item('feed', 'kg', ['name' => 'Fish meal', 'low_stock_threshold' => ['quantity' => '5', 'unit' => 'kg']]);

        $d = $this->dash();

        $this->assertSame('2', $this->kpis($d)['low_stock_items']['value']);
        $insights = collect($d['insights']['items'])->where('code', 'low_stock');
        $this->assertCount(2, $insights);
        $out = $insights->firstWhere('subject.id', $empty);
        $this->assertSame('critical', $out['severity']);
        $this->assertSame('Out of stock', $out['title']);
        $low = $insights->firstWhere('subject.id', $feed);
        $this->assertSame('warning', $low['severity']);
        $this->assertSame('40', $low['why']['stock']['quantity']);
        $this->assertSame('50', $low['why']['threshold']['quantity']);
        $this->assertNull($insights->firstWhere('subject.id', $plenty));
        $this->assertSame('critical', $d['insights']['items'][0]['severity'], 'most severe first');

        // Receiving stock clears the warning - the card is derived from the ledger, never stored.
        $this->receive($feed, $loc, '100', 'kg');
        $this->assertSame('1', $this->kpis($this->dash())['low_stock_items']['value']);

        $lotItem = $this->item('produce', 'piece', ['tracks_lots' => true, 'tracks_expiry' => true, 'name' => 'Tray eggs']);
        $this->receive($lotItem, $loc, '20', 'piece', ['lot' => ['code' => 'LOT-OLD', 'expires_on' => $this->day(-2)], 'recorded_at' => $this->at(24 * 8)]);
        $this->receive($lotItem, $loc, '30', 'piece', ['lot' => ['code' => 'LOT-SOON', 'expires_on' => $this->day(5)]]);
        $this->receive($lotItem, $loc, '30', 'piece', ['lot' => ['code' => 'LOT-LATER', 'expires_on' => $this->day(60)]]);
        $lots = collect($this->dash()['insights']['items'])->where('code', 'lot_expiry');
        $this->assertCount(2, $lots);
        $this->assertSame('critical', $lots->firstWhere('subject.reference', 'LOT-OLD')['severity']);
        $this->assertSame(5, $lots->firstWhere('subject.reference', 'LOT-SOON')['why']['days_left']);
        $this->assertNull($lots->firstWhere('subject.reference', 'LOT-LATER'));
    }

    // ------------------------------------------------------------------ tasks and the farm-local day

    public function test_work_block_counts_overdue_due_today_and_upcoming_from_the_task_rules(): void
    {
        $this->task(['title' => 'Overdue job', 'due_date' => $this->day(-2)])->assertCreated();
        $this->task(['title' => 'Overdue timed', 'due_date' => $this->today(), 'due_time' => '08:00'])->assertCreated();
        $this->task(['title' => 'Today job', 'due_date' => $this->today()])->assertCreated();
        $this->task(['title' => 'Today later', 'due_date' => $this->today(), 'due_time' => '18:00'])->assertCreated();
        $this->task(['title' => 'Tomorrow', 'due_date' => $this->day(1)])->assertCreated();
        $this->task(['title' => 'Next month', 'due_date' => $this->day(30)])->assertCreated();
        $done = $this->task(['title' => 'Done already', 'due_date' => $this->today()])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tasks/'.$done.'/complete', ['idempotency_key' => $this->key()])->assertOk();
        $cancelled = $this->task(['title' => 'Cancelled', 'due_date' => $this->day(-5)])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tasks/'.$cancelled.'/cancel', ['reason' => 'Not needed', 'idempotency_key' => $this->key()])->assertOk();

        $work = $this->dash()['work'];

        $this->assertSame(['overdue' => 2, 'due_today' => 2, 'upcoming' => 2, 'upcoming_7d' => 1, 'completed_today' => 1], $work['counts']);
        $this->assertSame(['Overdue job', 'Overdue timed', 'Today later', 'Today job'], array_column($work['today_and_overdue'], 'title'));
        $this->assertSame(['overdue', 'overdue', 'due_today', 'due_today'], array_column($work['today_and_overdue'], 'due_state'));
        $this->assertSame(['Tomorrow', 'Next month'], array_column($work['upcoming'], 'title'));
        $this->assertSame(['Done already'], array_column($work['recently_completed'], 'title'));
        $overdue = collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'overdue_tasks');
        $this->assertSame('warning', $overdue['severity']);
        $this->assertSame(2, $overdue['why']['overdue']);
        $this->assertSame($overdue['why']['overdue'], $this->getJson('/api/v1/tasks?due_state=overdue')->json('meta.total'), 'the dashboard agrees with the task list');
    }

    public function test_many_overdue_tasks_escalate_to_critical(): void
    {
        foreach (range(1, 5) as $i) {
            $this->task(['title' => "Late $i", 'due_date' => $this->day(-$i)])->assertCreated();
        }
        $d = $this->dash();
        $this->assertSame('critical', collect($d['insights']['items'])->firstWhere('code', 'overdue_tasks')['severity']);
        $this->assertSame('overdue_tasks', $d['priority'][0]['code']);
    }

    public function test_day_boundaries_follow_the_farm_timezone_not_utc(): void
    {
        $cycle = $this->layers(100);
        $this->task(['title' => 'Yesterday Lagos', 'due_date' => '2026-10-14'])->assertCreated();
        $this->task(['title' => 'Today Lagos', 'due_date' => '2026-10-15'])->assertCreated();
        $this->eggs($cycle, 10, 11); // 2026-10-14 00:00 UTC = 01:00 Lagos on the 14th

        // 23:30 UTC on the 14th is already 00:30 on the 15th in Lagos.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));
        $this->eggs($cycle, 7, 0);
        $this->eggs($cycle, 5, 1); // 22:30 UTC = 23:30 Lagos on the 14th - yesterday

        $d = $this->dash();

        $this->assertSame('2026-10-15', $d['farm']['today']);
        $this->assertTrue($d['calendar']['days'][0]['is_today']);
        $this->assertSame('2026-10-15', $d['calendar']['days'][0]['date']);
        $this->assertSame('7', $this->kpis($d)['eggs_today']['value'], 'only collections made on the farm-local 15th count');
        $this->assertSame(1, $d['work']['counts']['overdue']);
        $this->assertSame(1, $d['work']['counts']['due_today']);
        $this->assertSame(['Yesterday Lagos'], array_column(array_filter($d['work']['today_and_overdue'], fn ($t) => $t['due_state'] === 'overdue'), 'title'));

        // A moment later in the same Lagos day the picture is unchanged; at 23:00 UTC the next UTC day has not started.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 00:30:00', 'UTC'));
        $this->assertSame('2026-10-15', $this->dash()['farm']['today']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:00:00', 'UTC'));
        $this->assertSame('2026-10-16', $this->dash()['farm']['today']);
    }

    public function test_workers_see_only_their_visible_tasks_and_counts(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $this->task(['title' => 'Wale feeds', 'assigned_user_id' => $worker->id, 'due_date' => $this->day(-1)])->assertCreated();
        $this->task(['title' => 'Owner only', 'due_date' => $this->day(-1)])->assertCreated();

        $this->assertSame(2, $this->dash()['work']['counts']['overdue']);

        $this->signInAs($worker);
        $work = $this->dash()['work'];
        $this->assertSame(1, $work['counts']['overdue']);
        $this->assertSame(['Wale feeds'], array_column($work['today_and_overdue'], 'title'));
        $overdue = collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'overdue_tasks');
        $this->assertSame(1, $overdue['why']['overdue'], 'the insight counts only what the viewer can see');
    }

    // ------------------------------------------------------------------ calendar

    public function test_dashboard_calendar_summarises_days_and_milestones_from_the_calendar_read_model(): void
    {
        $cycle = $this->layers(100, ['expected_end_date' => $this->day(3)]);
        $this->task(['title' => 'A', 'due_date' => $this->today()])->assertCreated();
        $this->task(['title' => 'B', 'due_date' => $this->day(1)])->assertCreated();
        $this->task(['title' => 'C', 'due_date' => $this->day(1)])->assertCreated();
        $done = $this->task(['title' => 'D', 'due_date' => $this->day(1)])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tasks/'.$done.'/complete', ['idempotency_key' => $this->key()])->assertOk();
        $this->task(['title' => 'Past', 'due_date' => $this->day(-1)])->assertCreated();

        $strip = $this->getJson('/api/v1/dashboard/calendar')->assertOk()->json('data');

        $this->assertSame($this->today(), $strip['from']);
        $this->assertSame($this->day(6), $strip['to']);
        $this->assertCount(7, $strip['days']);
        $this->assertSame(1, $strip['days'][0]['tasks']['due_today']);
        $this->assertSame(2, $strip['days'][1]['tasks']['upcoming']);
        $this->assertSame(1, $strip['days'][1]['tasks']['completed']);
        $this->assertSame(3, $strip['days'][1]['tasks']['open'] + $strip['days'][1]['tasks']['completed'] - 0);
        $this->assertSame('cycle_expected_end', $strip['days'][3]['milestones'][0]['code']);
        $this->assertSame($cycle, $strip['days'][3]['milestones'][0]['source']['id']);
        $this->assertSame([], $strip['days'][2]['milestones']);

        $wide = $this->getJson('/api/v1/dashboard/calendar?from='.$this->day(-1).'&days=3')->assertOk()->json('data');
        $this->assertCount(3, $wide['days']);
        $this->assertSame(1, $wide['days'][0]['tasks']['overdue']);
        // Same numbers as the calendar endpoint itself.
        $range = $this->getJson('/api/v1/calendar?from='.$this->day(-1).'&to='.$this->day(1))->assertOk()->json('data');
        $this->assertSame(count(array_filter($range, fn ($i) => $i['kind'] === 'task')), array_sum(array_map(fn ($d) => $d['tasks']['open'] + $d['tasks']['completed'], $wide['days'])) - 0);

        $this->getJson('/api/v1/dashboard/calendar?days=0')->assertUnprocessable()->assertJsonValidationErrors('days');
        $this->getJson('/api/v1/dashboard/calendar?days=32')->assertUnprocessable()->assertJsonValidationErrors('days');
        $this->getJson('/api/v1/dashboard/calendar?from=14-10-2026')->assertUnprocessable()->assertJsonValidationErrors('from');
    }

    public function test_embedded_calendar_matches_the_standalone_strip(): void
    {
        $this->layers(100, ['expected_end_date' => $this->day(2)]);
        $this->task(['due_date' => $this->day(2)])->assertCreated();

        $this->assertEquals($this->getJson('/api/v1/dashboard/calendar')->json('data'), $this->dash()['calendar']);
    }

    // ------------------------------------------------------------------ deterministic insights

    public function test_mortality_threshold_insight_is_rule_based_explainable_and_repeatable(): void
    {
        $cycle = $this->layers(100, ['name' => 'Broilers A']);
        $this->mortality($cycle, 1);
        $this->assertSame([], $this->insightCodes('mortality_threshold'), 'one death is below the minimum count');

        $this->mortality($cycle, 1);
        $this->assertSame(['mortality_threshold'], $this->insightCodes('mortality_threshold'), '2 deaths of 100 is 2%: warning');
        $first = collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'mortality_threshold');
        $this->assertSame('warning', $first['severity']);
        $this->assertSame($cycle, $first['subject']['id']);
        $this->assertSame(['rule' => 'mortality_threshold', 'window_days' => 7, 'from' => $this->day(-6), 'to' => $this->today(), 'deaths' => 2, 'opening_population' => 100,
            'rate_percent' => '2', 'thresholds' => ['min_deaths' => 2, 'warning_percent' => '2', 'critical_percent' => '5']], $first['why']);
        $this->assertStringContainsString('Broilers A', $first['message']);

        $this->mortality($cycle, 4); // 6 of 100 = 6%
        $second = collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'mortality_threshold');
        $this->assertSame('critical', $second['severity']);
        $this->assertSame('6', $second['why']['rate_percent']);
        $this->assertSame($second, collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'mortality_threshold'), 'same data, same insight');

        // Deaths older than the 7-day window are not part of the rule.
        $other = $this->layers(100, ['name' => 'Broilers B']);
        $this->record($other, 'mortality', ['quantity' => 10, 'cause' => 'Heat'], 24 * 9)->assertCreated();
        $this->assertSame([$cycle], array_column(array_column($this->getJson('/api/v1/insights')->json('data'), 'subject'), 'id'));
    }

    private function insightCodes(string $code): array
    {
        return array_values(array_column(array_filter($this->getJson('/api/v1/insights')->assertOk()->json('data'), fn ($i) => $i['code'] === $code), 'code'));
    }

    // ------------------------------------------------------------------ roles and finance privacy

    /** A populated farm: a cycle with deaths, an overdue task, an invoiced and part-paid sale, an expense and some income. */
    private function populatedFarm(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 3);
        $this->task(['title' => 'Feed layers', 'due_date' => $this->day(-1)])->assertCreated();
        [, $invoice] = $this->invoicedSale('3000.00');
        $this->pay($invoice['id'], '1000.25')->assertCreated();
        $this->expense('250.10');
        $this->income('0.20');
    }

    public function test_each_role_gets_only_the_blocks_its_permissions_allow(): void
    {
        $this->populatedFarm();
        $money = ['income_month', 'expense_month', 'net_month', 'sales_month', 'receivables'];
        $moneyKinds = ['sale', 'payment', 'purchase', 'finance_transaction'];

        $expect = [
            // role => [finance section, sales section, health section, breeding section, money cards, quick record, money activity]
            'owner' => [true, true, true, true, true, true, true],
            'manager' => [true, true, true, true, true, true, true],
            'finance' => [true, true, false, false, true, false, true],
            'farm_worker' => [false, false, true, true, false, true, false],
            'vet' => [false, false, true, true, false, false, false],
        ];
        foreach ($expect as $role => [$finance, $sales, $health, $breeding, $hasMoney, $hasQuickRecord, $hasMoneyActivity]) {
            $user = $role === 'owner' ? $this->owner : $this->member(FarmRole::from($role), null, ucfirst($role));
            $this->signInAs($user);
            $d = $this->dash();
            $codes = array_column($d['kpis'], 'code');

            $this->assertSame($role, $d['viewer']['role']);
            $this->assertSame($finance, $d['sections']['finance'], "$role finance section");
            $this->assertSame($sales, $d['sections']['sales'], "$role sales section");
            $this->assertSame($health, $d['sections']['health'], "$role health section");
            $this->assertSame($breeding, $d['sections']['breeding'], "$role breeding section");
            $this->assertSame($hasMoney, (bool) array_intersect($money, $codes), "$role money cards");
            $this->assertSame($hasQuickRecord, $d['quick_record'] !== [], "$role quick record");
            $kinds = array_unique(array_column($d['recent_activity'], 'kind'));
            $this->assertSame($hasMoneyActivity, (bool) array_intersect($moneyKinds, $kinds), "$role money activity");
            $this->assertNotNull($d['work'], "$role sees work (task.view)");
            $this->assertArrayHasKey('livestock_population', array_column($d['kpis'], null, 'code'), "$role sees production");

            if (! $hasMoney) {
                $raw = $this->getJson('/api/v1/dashboard')->getContent();
                $this->assertStringNotContainsString('3000.00', $raw, "$role must not receive sale amounts");
                $this->assertStringNotContainsString('INV-', $raw);
                $this->assertStringNotContainsString('250.10', $raw);
                $this->assertSame([], array_values(array_filter($this->getJson('/api/v1/insights')->json('data'), fn ($i) => $i['code'] === 'overdue_invoices')));
            }
        }
    }

    public function test_quick_add_and_quick_record_follow_permissions_and_operations(): void
    {
        $this->layers(100);
        $this->signInAs($this->member(FarmRole::FarmWorker, null, 'W'));
        $add = array_column($this->dash()['quick_add'], 'code');
        $this->assertContains('health_record', $add);
        $this->assertContains('breeding_project', $add);
        foreach (['sale', 'purchase', 'expense', 'income', 'stock_in', 'task', 'production_cycle', 'contact'] as $code) {
            $this->assertNotContains($code, $add, "a Farm Worker cannot $code");
        }
        $this->assertContains('mortality', array_column($this->dash()['quick_record'], 'type'));

        $this->signInAs($this->member(FarmRole::Vet, null, 'V'));
        $this->assertSame(['health_record', 'breeding_project'], array_column($this->dash()['quick_add'], 'code'));
        $this->assertSame([], $this->dash()['quick_record']);

        $this->signInAs($this->member(FarmRole::Finance, null, 'F'));
        $this->assertEqualsCanonicalizing(['sale', 'purchase', 'expense', 'income', 'contact'], array_column($this->dash()['quick_add'], 'code'));
    }

    public function test_finance_cards_use_exact_decimals_and_farm_local_months(): void
    {
        $this->income('0.10');
        $this->income('0.20');
        $this->expense('0.05');
        $this->postJson('/api/v1/income', ['finance_category_id' => $this->category('egg_sales', 'income'), 'amount' => '999.99', 'occurred_on' => '2026-09-30', 'idempotency_key' => $this->key()])->assertCreated();

        $k = $this->kpis($this->dash());

        $this->assertSame('0.30', $k['income_month']['value'], '0.10 + 0.20 is exactly 0.30');
        $this->assertSame('0.05', $k['expense_month']['value']);
        $this->assertSame('0.25', $k['net_month']['value']);
        $this->assertSame('NGN', $k['income_month']['unit']);
        $this->assertSame('2026-10-01', $k['income_month']['detail']['from']);
        $this->assertSame($this->today(), $k['income_month']['detail']['to']);
        $summary = $this->getJson('/api/v1/finance/summary?from=2026-10-01&to='.$this->today())->json('data.totals');
        $this->assertSame([$summary['income'], $summary['expense'], $summary['net']], [$k['income_month']['value'], $k['expense_month']['value'], $k['net_month']['value']], 'the same figures as the finance summary that owns them');
    }

    public function test_sales_and_receivables_are_exact_exclude_cancelled_and_reversed_and_respect_the_month(): void
    {
        [, $invoice] = $this->invoicedSale('3000.00', ['issue_date' => $this->day(-5), 'due_date' => $this->day(-1)], 24 * 5, 24 * 12);
        $payment = $this->pay($invoice['id'], '1000.25')->assertCreated()->json('data');

        $d = $this->dash();
        $k = $this->kpis($d);
        $this->assertSame('3000.00', $k['sales_month']['value']);
        $this->assertSame('1999.75', $k['receivables']['value']);
        $this->assertSame(1, $k['receivables']['detail']['invoices']);
        $this->assertSame(1, $k['receivables']['detail']['overdue_invoices']);
        $this->assertSame('1999.75', $k['receivables']['detail']['overdue_outstanding']);
        $overdue = collect($d['insights']['items'])->firstWhere('code', 'overdue_invoices');
        $this->assertSame('warning', $overdue['severity']);
        $this->assertSame('1999.75', $overdue['why']['overdue_outstanding']);

        // Reversing the payment puts the money back on the receivable; nothing is stored on the dashboard side.
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Bounced', 'idempotency_key' => $this->key()])->assertOk();
        $this->assertSame('3000.00', $this->kpis($this->dash())['receivables']['value']);

        // Paying in full removes the invoice from the receivable and from the overdue insight.
        $this->pay($invoice['id'], '3000.00')->assertCreated();
        $d = $this->dash();
        $this->assertSame('0.00', $this->kpis($d)['receivables']['value'], 'a farm that invoices keeps the card; nothing is owed');
        $this->assertSame(0, $this->kpis($d)['receivables']['detail']['overdue_invoices']);
        $this->assertNull(collect($d['insights']['items'])->firstWhere('code', 'overdue_invoices'));

        // 22:00 UTC on 30 Sep is 23:00 Lagos (September); 23:30 UTC is 00:30 on 1 Oct in Lagos (October).
        $eggs = $this->item();
        $loc = $this->store();
        $this->receive($eggs, $loc, '500', 'piece', ['recorded_at' => '2026-09-01T08:00:00Z']);
        $line = [['kind' => 'stock', 'inventory_item_id' => $eggs, 'storage_location_id' => $loc, 'components' => [['quantity' => '10', 'unit' => 'piece']], 'amount' => '1500.50']];
        $this->postJson('/api/v1/sales', ['recorded_at' => '2026-09-30T22:00:00Z', 'idempotency_key' => $this->key(), 'items' => $line])->assertCreated();
        $this->postJson('/api/v1/sales', ['recorded_at' => '2026-09-30T23:30:00Z', 'idempotency_key' => $this->key(), 'items' => $line])->assertCreated();
        $this->assertSame('4500.50', $this->kpis($this->dash())['sales_month']['value'], '3000.00 + 1500.50; the 22:00 UTC sale belongs to September');

        $cancelled = $this->postJson('/api/v1/sales', ['recorded_at' => $this->at(1), 'idempotency_key' => $this->key(), 'items' => $line])->assertCreated()->json('data');
        $this->assertSame('6001.00', $this->kpis($this->dash())['sales_month']['value']);
        $this->postJson('/api/v1/sales/'.$cancelled['id'].'/cancel', ['reason' => 'Mistake', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertOk();
        $this->assertSame('4500.50', $this->kpis($this->dash())['sales_month']['value']);
    }

    public function test_voided_invoices_leave_the_receivable(): void
    {
        [, $invoice] = $this->invoicedSale('800.00');
        $this->assertSame('800.00', $this->kpis($this->dash())['receivables']['value']);
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'Wrong buyer', 'idempotency_key' => $this->key()])->assertOk();
        $this->assertArrayNotHasKey('receivables', $this->kpis($this->dash()));
    }

    // ------------------------------------------------------------------ recent activity

    public function test_recent_activity_is_newest_first_permission_filtered_and_skips_reversed_and_cancelled(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 2, 6);
        $this->expense('100.00');
        [$sale, $invoice] = $this->invoicedSale('500.00');
        $payment = $this->pay($invoice['id'], '500.00')->assertCreated()->json('data');
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Bounced', 'idempotency_key' => $this->key()])->assertOk();
        [$gone] = $this->invoicedSale('900.00');
        $this->postJson('/api/v1/sales/'.$gone['id'].'/cancel', ['reason' => 'Mistake', 'recorded_at' => $this->at(0), 'idempotency_key' => $this->key()])->assertOk();

        $feed = $this->dash()['recent_activity'];

        $times = array_column($feed, 'occurred_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'newest first');
        $ids = array_column($feed, 'id');
        $this->assertContains('sale:'.$sale['id'], $ids);
        $this->assertNotContains('sale:'.$gone['id'], $ids, 'a cancelled sale is not activity');
        $this->assertSame([], array_values(array_filter($feed, fn ($i) => $i['kind'] === 'payment')), 'a reversed payment is not activity');
        $first = collect($feed)->firstWhere('kind', 'operational_record');
        $this->assertSame('Mortality', $first['title']);
        $this->assertSame('2 head', $first['summary']);
        $this->assertSame('Olu Owner', $first['actor']['name']);
        $this->assertSame($cycle, $first['production_cycle']['id']);
        $this->assertLessThanOrEqual(15, count($feed));
        $this->assertSame(1, count(array_filter($feed, fn ($i) => $i['kind'] === 'finance_transaction')), 'only the manual expense; sale/payment income rows are not repeated');
    }

    // ------------------------------------------------------------------ health and breeding

    public function test_medicine_withdrawal_is_a_card_and_an_insight_until_the_event_is_reversed(): void
    {
        $cycle = $this->layers(100);
        $loc = $this->store();
        $med = $this->item('medicine', 'ml', ['name' => 'Oxytet']);
        $this->putJson('/api/v1/health/medicines/'.$med.'/profile', ['default_withdrawal_days' => 7])->assertOk();
        $this->receive($med, $loc, '500', 'ml');
        $record = $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vaccination', 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'details' => ['target_disease' => 'Newcastle disease'],
            'medicines' => [['inventory_item_id' => $med, 'storage_location_id' => $loc, 'components' => [['quantity' => '10', 'unit' => 'ml']]]]])->assertCreated()->json('data');

        $d = $this->dash();
        $this->assertSame('1', $this->kpis($d)['active_withdrawals']['value']);
        $insight = collect($d['insights']['items'])->firstWhere('code', 'medicine_withdrawal');
        $this->assertSame('info', $insight['severity']);
        $this->assertSame(1, $insight['why']['lines']);
        $this->assertContains('health_record', array_column($d['recent_activity'], 'kind'));

        $this->postJson('/api/v1/health-records/'.$record['id'].'/reverse', ['reason' => 'Wrong flock', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();
        $d = $this->dash();
        $this->assertArrayNotHasKey('active_withdrawals', $this->kpis($d));
        $this->assertNull(collect($d['insights']['items'])->firstWhere('code', 'medicine_withdrawal'));
        $this->assertNotContains('health_record', array_column($d['recent_activity'], 'kind'));
    }

    public function test_breeding_expectations_become_due_soon_and_overdue_insights(): void
    {
        $soon = $this->layers(100);
        $late = $this->layers(100);
        $near = $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $soon, 'workflow' => 'incubation', 'start_date' => $this->day(-17), 'eggs_set' => 50, 'idempotency_key' => $this->key()])->assertCreated()->json('data');
        $old = $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $late, 'workflow' => 'incubation', 'start_date' => $this->day(-40), 'eggs_set' => 50, 'idempotency_key' => $this->key()])->assertCreated()->json('data');

        $d = $this->dash();
        $items = collect($this->getJson('/api/v1/insights')->json('data'));

        $this->assertSame('2', $this->kpis($d)['active_breeding_projects']['value']);
        $this->assertSame('info', $items->firstWhere('code', 'breeding_due_soon')['severity']);
        $this->assertSame($near['id'], $items->firstWhere('code', 'breeding_due_soon')['subject']['id']);
        $this->assertSame('warning', $items->firstWhere('code', 'breeding_overdue')['severity']);
        $this->assertSame($old['id'], $items->firstWhere('code', 'breeding_overdue')['subject']['id']);

        $this->postJson('/api/v1/breeding-projects/'.$old['id'].'/cancel', ['reason' => 'Abandoned', 'idempotency_key' => $this->key()])->assertOk();
        $this->assertNull(collect($this->getJson('/api/v1/insights')->json('data'))->firstWhere('code', 'breeding_overdue'));
    }

    // ------------------------------------------------------------------ insights endpoint

    public function test_insights_endpoint_orders_filters_limits_and_validates(): void
    {
        $cycle = $this->layers(100, ['expected_end_date' => $this->day(-3)]);
        $this->mortality($cycle, 6);
        $this->task(['due_date' => $this->day(-1)])->assertCreated();
        $this->item('feed', 'kg', ['low_stock_threshold' => ['quantity' => '10', 'unit' => 'kg']]);

        $all = $this->getJson('/api/v1/insights')->assertOk();
        $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $ranks = array_map(fn ($i) => $rank[$i['severity']], $all->json('data'));
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'critical first');
        $this->assertSame(['critical' => 2, 'warning' => 1, 'info' => 1], $all->json('meta.by_severity'));
        foreach ($all->json('data') as $insight) {
            $this->assertSame($insight['code'], $insight['why']['rule']);
            $this->assertSame($insight['code'].':'.($insight['subject']['id'] ?? 'farm'), $insight['id']);
        }
        $this->assertSame(['critical'], array_values(array_unique(array_column($this->getJson('/api/v1/insights?severity=critical')->json('data'), 'severity'))));
        $this->assertCount(1, $this->getJson('/api/v1/insights?limit=1')->json('data'));
        $this->assertSame(4, $this->getJson('/api/v1/insights?limit=1')->json('meta.total'));
        $this->getJson('/api/v1/insights?severity=urgent')->assertUnprocessable()->assertJsonValidationErrors('severity');
        $this->getJson('/api/v1/insights?limit=0')->assertUnprocessable();
        $this->assertSame('cycle_past_expected_end', collect($all->json('data'))->firstWhere('severity', 'info')['code']);
    }

    // ------------------------------------------------------------------ isolation, read-only and performance

    public function test_another_farms_data_never_appears_and_the_dashboard_writes_nothing(): void
    {
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $cycle = $this->layers(70);
        $this->mortality($cycle, 9);
        $this->task(['title' => 'Other farm task', 'due_date' => $this->day(-3)])->assertCreated();
        $this->invoicedSale('7777.00');
        $this->item('feed', 'kg', ['low_stock_threshold' => ['quantity' => '10', 'unit' => 'kg']]);
        $other = $this->dash();
        $this->assertSame('61', $this->kpis($other)['livestock_population']['value']);
        $this->assertSame(1, $other['work']['counts']['overdue']);

        $this->signInAs($this->owner);
        $mine = $this->dash();
        $this->assertSame([], $mine['kpis']);
        $this->assertSame(0, $mine['production']['total_active']);
        $this->assertSame(0, $mine['work']['counts']['overdue']);
        $this->assertSame(0, $mine['insights']['total']);
        $this->assertSame([], $mine['recent_activity']);
        $this->assertStringNotContainsString('7777', json_encode($mine));
        $this->assertStringNotContainsString('Other farm task', json_encode($mine));
        $this->assertSame([], $this->getJson('/api/v1/insights')->json('data'));

        $tables = ['operational_records', 'population_movements', 'tasks', 'inventory_movements', 'finance_transactions', 'payments', 'sales', 'invoices'];
        $before = array_map(fn ($t) => DB::table($t)->count(), $tables);
        $this->dash();
        $this->getJson('/api/v1/insights');
        $this->getJson('/api/v1/dashboard/calendar');
        $this->assertSame($before, array_map(fn ($t) => DB::table($t)->count(), $tables), 'a dashboard read never writes');
        foreach (Schema::getTableListing() as $table) {
            $this->assertDoesNotMatchRegularExpression('/dashboard|insight/', $table, 'dashboard totals and insights are never persisted');
        }
    }

    private function dashboardQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/dashboard')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function grow(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $cycle = $this->layers(50 + $i);
            $this->mortality($cycle, 3);
            $this->eggs($cycle, 10);
            $crop = $this->yam();
            $this->harvest($crop, '10', 'kg');
            $this->task(['due_date' => $this->day(-1 - $i)])->assertCreated();
            $this->item('feed', 'kg', ['low_stock_threshold' => ['quantity' => '10', 'unit' => 'kg']]);
        }
    }

    public function test_query_count_does_not_grow_with_the_number_of_cycles_tasks_or_items(): void
    {
        $this->grow(1);
        $this->income('10.00');
        $this->invoicedSale('100.00');
        $small = $this->dashboardQueries();

        $this->grow(5);
        $large = $this->dashboardQueries();

        $this->assertSame($small, $large, "a dashboard over 6x the data ran $large queries instead of $small");
        $this->assertLessThanOrEqual(60, $large);
        $d = $this->dash();
        $this->assertSame(12, $d['production']['total_active']);
        $this->assertCount(6, $d['production']['items']);
    }

    public function test_openapi_documents_the_dashboard_endpoints(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true);

        foreach (['/dashboard', '/dashboard/calendar', '/insights'] as $path) {
            $this->assertArrayHasKey('get', $spec['paths'][$path] ?? [], "$path is documented");
        }
        $this->assertStringContainsString('task.view', $spec['paths']['/dashboard/calendar']['get']['description']);
        $this->assertStringContainsString('deterministic', $spec['paths']['/insights']['get']['description']);
        $this->assertFileExists(base_path('docs/api/PHASE-16-DASHBOARD.md'));
    }
}
