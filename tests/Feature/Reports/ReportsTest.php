<?php

namespace Tests\Feature\Reports;

use App\Enums\FarmRole;
use App\Models\FarmMembership;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Team\TeamTestCase;

/**
 * Report derivations. Every report is read from the authoritative ledgers/records created through the public API; nothing is seeded into
 * report tables (there are none).
 */
class ReportsTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-business');
        $this->bootClock();
        $this->signInAs($this->owner);
    }

    private function codes(): array
    {
        return array_column($this->getJson('/api/v1/reports')->assertOk()->json('data'), 'code');
    }

    // ------------------------------------------------------------------ catalogue and access

    public function test_catalogue_lists_every_report_for_the_owner_with_filters_and_formats(): void
    {
        $data = $this->getJson('/api/v1/reports')->assertOk()->json('data');
        $this->assertCount(count(ReportService::REPORTS), $data);
        $population = collect($data)->firstWhere('code', 'livestock_population');
        $this->assertSame(['from', 'to', 'status', 'production_cycle_id'], $population['filters']);
        $this->assertSame(['csv', 'xlsx', 'pdf'], $population['export_formats']);
        $this->assertTrue($population['available']);
        $this->assertTrue($population['exportable']);
        $this->assertSame('livestock', $population['applies_to']);
    }

    public function test_catalogue_is_operation_aware(): void
    {
        $by = fn () => collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('code');
        $this->assertFalse($by()['livestock_population']['relevant'], 'a farm with no operations or cycles has no livestock content');
        $this->assertFalse($by()['crop_performance']['relevant']);
        $this->assertTrue($by()['income_expense']['relevant']);

        $this->yam();
        $this->assertTrue($by()['crop_performance']['relevant']);
        $this->assertFalse($by()['livestock_population']['relevant'], 'a crop-only farm gets no livestock reports as relevant');
        $this->layers();
        $this->assertTrue($by()['livestock_population']['relevant']);
    }

    public function test_reports_follow_the_data_permissions_of_each_role(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $vet = $this->member(FarmRole::Vet, null, 'Vera Vet');
        $finance = $this->member(FarmRole::Finance, null, 'Fola Finance');
        $manager = $this->member(FarmRole::Manager, null, 'Mo Manager');

        $this->signInAs($worker);
        $w = $this->codes();
        foreach (['livestock_population', 'mortality', 'production_output', 'inventory_stock', 'task_compliance', 'cycle_performance'] as $ok) {
            $this->assertContains($ok, $w);
        }
        foreach (['income_expense', 'cycle_profitability', 'sales_summary', 'receivables', 'contact_history'] as $hidden) {
            $this->assertNotContains($hidden, $w, "$hidden must not be advertised to a farm worker's catalogue");
        }

        $this->signInAs($vet);
        $v = $this->codes();
        $this->assertContains('health_treatment', $v);
        $this->assertContains('breeding_outcomes', $v);
        foreach (['income_expense', 'sales_summary', 'receivables', 'contact_history', 'cycle_profitability'] as $hidden) {
            $this->assertNotContains($hidden, $v, "$hidden must be invisible to a vet");
        }

        $this->signInAs($finance);
        $f = $this->codes();
        foreach (['income_expense', 'cycle_profitability', 'sales_summary', 'receivables', 'contact_history'] as $ok) {
            $this->assertContains($ok, $f);
        }
        $this->assertNotContains('health_treatment', $f);
        $this->assertNotContains('breeding_outcomes', $f);

        $this->signInAs($manager);
        $this->assertCount(count(ReportService::REPORTS), $this->codes());
    }

    public function test_running_a_report_without_its_data_permission_is_forbidden_and_unknown_reports_are_404(): void
    {
        $this->signInAs($this->member(FarmRole::FarmWorker));
        $this->getJson('/api/v1/reports/income_expense')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/v1/reports/sales_summary')->assertForbidden();
        $this->getJson('/api/v1/reports/receivables')->assertForbidden();
        $this->getJson('/api/v1/reports/nope')->assertNotFound()->assertJsonPath('code', 'report_not_found');

        $this->signInAs($this->member(FarmRole::Vet));
        $this->getJson('/api/v1/reports/cycle_profitability')->assertForbidden();
        $this->report('health_treatment');
    }

    public function test_advanced_reports_need_the_plan_feature_and_core_reports_do_not(): void
    {
        $this->onPlan('free');
        $catalogue = collect($this->getJson('/api/v1/reports')->json('data'))->keyBy('code');
        $this->assertFalse($catalogue['income_expense']['available']);
        $this->assertSame('feature_not_available', $catalogue['income_expense']['unavailable_reason']);
        $this->assertSame('advanced_reports', $catalogue['income_expense']['required_feature']);
        $this->assertTrue($catalogue['inventory_stock']['available']);
        $this->assertFalse($catalogue['inventory_stock']['exportable'], 'exports need the data_export feature');

        $this->getJson('/api/v1/reports/income_expense')->assertForbidden()->assertJsonPath('code', 'feature_not_available');
        $this->report('inventory_stock');
    }

    public function test_requires_authentication_and_validates_filters(): void
    {
        $this->report('livestock_population', ['from' => '2026-10-10', 'to' => '2026-10-01'], 422);
        $this->report('livestock_population', ['from' => 'bad'], 422);
        $this->report('livestock_population', ['production_cycle_id' => '6f1c4f8e-0000-7000-8000-000000000000'], 422)->assertJsonValidationErrors('production_cycle_id');
        $this->report('livestock_population', ['per_page' => 501], 422);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/reports')->assertUnauthorized();
        $this->getJson('/api/v1/reports/mortality')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ population, mortality, output

    public function test_livestock_population_reconciles_to_the_ledger_and_nets_reversals(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 5);
        $wrong = $this->mortality($cycle, 3);
        $this->reverseRecord($wrong['id']);

        $r = $this->report('livestock_population');
        $row = $r->json('data.rows.0');
        $this->assertSame(100, $row['opening']);
        $this->assertSame(0, $row['initial'], 'the stocking happened before the period');
        $this->assertSame(-5, $row['deaths'], 'a reversed death nets to zero');
        $this->assertSame(95, $row['closing']);
        $this->assertSame($row['opening'] + $row['initial'] + $row['births'] + $row['deaths'] + $row['sold'] + $row['adjustments'] + $row['other'], $row['closing']);
        $this->assertSame(95, $this->getJson('/api/v1/production-cycles/'.$cycle)->json('data.livestock.current_population'), 'report equals the ledger the cycle reads');
        $this->assertSame(95, $r->json('data.summary.closing'));
        $this->assertSame('Africa/Lagos', $r->json('data.timezone'));
    }

    public function test_population_in_period_includes_initial_stocking_for_a_new_cycle(): void
    {
        $cycle = $this->layers(40, ['start_date' => $this->day(-3)]);
        $this->mortality($cycle, 2);
        $row = $this->rows('livestock_population')[0];
        $this->assertSame(0, $row['opening']);
        $this->assertSame(40, $row['initial']);
        $this->assertSame(-2, $row['deaths']);
        $this->assertSame(38, $row['closing']);

        $past = $this->rows('livestock_population', ['from' => $this->day(-60), 'to' => $this->day(-10)]);
        $this->assertSame([], $past, 'a cycle that started later has no ledger rows yet');
    }

    public function test_mortality_report_groups_by_cause_and_excludes_reversed_records(): void
    {
        $cycle = $this->layers(200);
        $this->mortality($cycle, 4, 2, 'Disease');
        $this->mortality($cycle, 2, 3, 'disease ');
        $this->mortality($cycle, 1, 3, 'Predator');
        $gone = $this->mortality($cycle, 9, 3, 'Heat');
        $this->reverseRecord($gone['id']);

        $r = $this->report('mortality');
        $rows = collect($r->json('data.rows'))->keyBy(fn ($x) => strtolower(trim($x['cause'])));
        $this->assertSame(6, $rows['disease']['deaths'], 'case and spacing do not split a cause');
        $this->assertSame(2, $rows['disease']['records']);
        $this->assertSame(1, $rows['predator']['deaths']);
        $this->assertFalse($rows->has('heat'));
        $this->assertSame(7, $r->json('data.summary.deaths'));
    }

    public function test_production_output_sums_within_one_unit_and_excludes_reversed(): void
    {
        $cycle = $this->layers(100);
        $this->eggs($cycle, 120);
        $this->eggs($cycle, 30, 5);
        $bad = $this->eggs($cycle, 999, 4);
        $this->reverseRecord($bad['id']);

        $r = $this->report('production_output');
        $this->assertCount(1, $r->json('data.rows'));
        $this->assertSame('Eggs', $r->json('data.rows.0.product'));
        $this->assertSame('piece', $r->json('data.rows.0.unit'));
        $this->assertSame('150', $r->json('data.rows.0.quantity'));
        $this->assertSame(['Eggs (piece)' => '150'], (array) $r->json('data.summary.totals_by_product_unit'));
    }

    public function test_period_uses_farm_local_day_boundaries(): void
    {
        // 23:10 UTC on the 14th is 00:10 on the 15th in Lagos.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));
        $cycle = $this->layers(50);
        $this->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'mortality', 'recorded_at' => '2026-10-14T23:10:00Z', 'idempotency_key' => $this->key(), 'details' => ['quantity' => 3, 'cause' => 'Disease']])->assertCreated();

        $utcDay = $this->report('mortality', ['from' => '2026-10-14', 'to' => '2026-10-14'])->json('data.summary.deaths');
        $localDay = $this->report('mortality', ['from' => '2026-10-15', 'to' => '2026-10-15'])->json('data.summary.deaths');
        $this->assertSame(0, $utcDay, 'the UTC day is not the farm day');
        $this->assertSame(3, $localDay);
        $this->assertSame('2026-10-15', $this->report('mortality')->json('data.filters.to'), 'the default period ends on the farm-local today');
    }

    public function test_cycle_performance_reports_mortality_rate_and_crop_baseline(): void
    {
        $layers = $this->layers(200);
        $this->mortality($layers, 10);
        $yam = $this->yam();

        $rows = collect($this->rows('cycle_performance'))->keyBy('kind');
        $this->assertSame(200, $rows['livestock']['initial_population']);
        $this->assertSame(200, $rows['livestock']['opening_population']);
        $this->assertSame(190, $rows['livestock']['closing_population']);
        $this->assertSame(10, $rows['livestock']['deaths']);
        $this->assertSame('5', $rows['livestock']['mortality_rate']);
        $this->assertSame(500, $rows['crop']['planting_units']);
        $this->assertNull($rows['crop']['deaths']);
        $this->assertSame(['active'], array_unique(array_column($this->rows('cycle_performance'), 'status')));
        $this->assertCount(1, $this->rows('cycle_performance', ['kind' => 'crop']));
        $this->assertCount(1, $this->rows('cycle_performance', ['production_cycle_id' => $yam]));
    }

    public function test_livestock_growth_averages_weight_per_head(): void
    {
        $cycle = $this->layers(100);
        $this->record($cycle, 'weight', ['sample_size' => 10, 'components' => [['quantity' => '2500', 'unit' => 'g']]], 30)->assertCreated();
        $this->record($cycle, 'weight', ['sample_size' => 10, 'components' => [['quantity' => '3500', 'unit' => 'g']]], 2)->assertCreated();

        $row = $this->rows('livestock_growth')[0];
        $this->assertSame(2, $row['weigh_ins']);
        $this->assertSame(20, $row['head_sampled']);
        $this->assertSame('300', $row['average_weight_per_head']);
        $this->assertSame('350', $row['latest_average_weight_per_head']);
    }

    // ------------------------------------------------------------------ crops

    public function test_crop_performance_separates_planting_units_loss_and_harvest_by_unit(): void
    {
        $crop = $this->yam();
        $this->record($crop, 'planting', ['units_planted' => 300, 'components' => [['quantity' => '20', 'unit' => 'kg']]], 6)->assertCreated();
        $this->record($crop, 'crop_loss', ['units_lost' => 25, 'cause' => 'Flood'], 4)->assertCreated();
        $this->record($crop, 'establishment_check', ['established_units' => 250], 3)->assertCreated();
        $this->harvest($crop, '120.5');
        $this->harvest($crop, '30', 'kg', 3);
        $gone = $this->harvest($crop, '999', 'kg', 3);
        $this->reverseRecord($gone['id']);

        $r = $this->report('crop_performance');
        $row = $r->json('data.rows.0');
        $this->assertSame(500, $row['initial_planting_units']);
        $this->assertSame(300, $row['units_planted']);
        $this->assertSame(25, $row['units_lost']);
        $this->assertSame(250, $row['established_units']);
        $this->assertSame('150500', $row['harvest_quantity'], 'quantities are in the canonical normalised unit (grams for weight)');
        $this->assertSame('g', $row['harvest_unit']);
        $this->assertSame(['g' => '150500'], (array) $r->json('data.summary.harvest_totals_by_unit'));
    }

    // ------------------------------------------------------------------ inventory

    public function test_inventory_stock_is_derived_from_the_ledger_as_of_a_farm_local_day(): void
    {
        $loc = $this->store();
        $feed = $this->item('feed', 'kg', ['name' => 'Starter mash', 'low_stock_threshold' => ['quantity' => '50', 'unit' => 'kg']]);
        $this->receive($feed, $loc, '200', 'kg', ['recorded_at' => $this->at(100)]);
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $feed, 'storage_location_id' => $loc, 'reason' => 'use', 'components' => [['quantity' => '160', 'unit' => 'kg']], 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()])->assertCreated();

        $now = collect($this->rows('inventory_stock'))->firstWhere('item', 'Starter mash');
        $this->assertSame('40', $now['quantity']);
        $this->assertSame('kg', $now['unit']);
        $this->assertSame('yes', $now['low_stock']);

        $before = collect($this->rows('inventory_stock', ['as_of' => $this->day(-1)]))->firstWhere('item', 'Starter mash');
        $this->assertSame('200', $before['quantity'], 'the balance at the end of an earlier day ignores later movements');
        $this->assertSame('no', $before['low_stock']);
    }

    public function test_input_consumption_nets_reversals_and_ignores_other_stock_reasons(): void
    {
        $cycle = $this->layers(100);
        $loc = $this->store();
        $feed = $this->item('feed', 'kg', ['name' => 'Grower mash']);
        $this->receive($feed, $loc, '500', 'kg', ['recorded_at' => $this->at(100)]);
        $inv = ['item_id' => $feed, 'storage_location_id' => $loc];
        $this->record($cycle, 'feed_use', ['feed_name' => 'Grower', 'components' => [['quantity' => '25', 'unit' => 'kg']], 'inventory' => $inv], 5)->assertCreated();
        $this->record($cycle, 'feed_use', ['feed_name' => 'Grower', 'components' => [['quantity' => '10', 'unit' => 'kg']], 'inventory' => $inv], 4)->assertCreated();
        $wrong = $this->record($cycle, 'feed_use', ['feed_name' => 'Grower', 'components' => [['quantity' => '100', 'unit' => 'kg']], 'inventory' => $inv], 3)->assertCreated()->json('data');
        $this->reverseRecord($wrong['id']);
        $this->postJson('/api/v1/inventory/stock-out', ['inventory_item_id' => $feed, 'storage_location_id' => $loc, 'reason' => 'wasted', 'components' => [['quantity' => '7', 'unit' => 'kg']], 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key()])->assertCreated();

        $rows = $this->rows('input_consumption');
        $this->assertCount(1, $rows);
        $this->assertSame('35', $rows[0]['quantity'], 'use only, net of the reversed 100 kg');
        $this->assertSame('Grower mash', $rows[0]['item']);
        $this->assertSame('Feed and crop inputs', $rows[0]['origin']);
        $this->assertCount(1, $this->rows('input_consumption', ['production_cycle_id' => $cycle]));
        $this->assertCount(0, $this->rows('input_consumption', ['production_cycle_id' => $this->layers(10)]));
    }

    // ------------------------------------------------------------------ money

    public function test_income_expense_is_exact_and_nets_reversals(): void
    {
        $this->income('0.10');
        $this->income('0.20');
        $this->expense('0.05');
        $wrong = $this->income('500.00');
        $this->postJson('/api/v1/finance/transactions/'.$wrong['id'].'/reverse', ['reason' => 'Mistake', 'idempotency_key' => $this->key()])->assertCreated();

        $r = $this->report('income_expense');
        $this->assertSame('0.30', $r->json('data.summary.income'));
        $this->assertSame('0.05', $r->json('data.summary.expense'));
        $this->assertSame('0.25', $r->json('data.summary.net'));
        $this->assertSame('NGN', $r->json('data.summary.currency'));
        $byDirection = collect($r->json('data.rows'))->groupBy('direction')->map(fn ($g) => $g->sum(fn ($x) => (float) $x['amount']));
        $this->assertEqualsWithDelta(0.30, $byDirection['income'], 0.0001);
        $this->assertSame($this->getJson('/api/v1/finance/summary?from='.$r->json('data.filters.from').'&to='.$r->json('data.filters.to'))->json('data.totals.net'), $r->json('data.summary.net'), 'reconciles to the finance summary');

        $this->assertSame('0.00', $this->report('income_expense', ['from' => $this->day(-100), 'to' => $this->day(-50)])->json('data.summary.income'));
    }

    public function test_cycle_profitability_separates_cycle_money_from_unallocated_money(): void
    {
        $cycle = $this->layers(100);
        $this->income('1000.50', null, $cycle);
        $this->expense('400.25', null, $cycle);
        $this->income('10.00');

        $r = $this->report('cycle_profitability');
        $rows = collect($r->json('data.rows'));
        $mine = $rows->first(fn ($x) => $x['reference'] !== null);
        $this->assertSame('1000.50', $mine['income']);
        $this->assertSame('400.25', $mine['expense']);
        $this->assertSame('600.25', $mine['net']);
        $this->assertSame('10.00', $rows->first(fn ($x) => $x['reference'] === null)['income']);
        $this->assertSame('1010.50', $r->json('data.summary.income'));
        $this->assertSame('610.25', $r->json('data.summary.net'));
    }

    public function test_sales_summary_excludes_cancelled_sales_and_keeps_money_exact(): void
    {
        [$a] = $this->invoicedSale('100.10');
        [$b] = $this->invoicedSale('200.20');
        [$cancel] = $this->invoicedSale('999.99');
        $this->postJson('/api/v1/sales/'.$cancel['id'].'/cancel', ['reason' => 'Customer backed out', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertOk();

        $r = $this->report('sales_summary');
        $this->assertSame('300.30', $r->json('data.summary.total'));
        $this->assertSame(2, $r->json('data.summary.sales'));
        $this->assertSame(1, $r->json('data.summary.cancelled_sales'));
        $this->assertSame('300.30', $r->json('data.rows.0.amount'));
        $this->assertSame('Stock (produce)', $r->json('data.rows.0.kind'));
        $this->assertNull($r->json('data.rows.0.head_sold'));
    }

    public function test_receivables_net_reversed_payments_and_exclude_void_invoices(): void
    {
        $customer = $this->customer('Mama Ngozi');
        [, $inv1] = $this->invoicedSale('1000.00', [], 3, $customer);
        [, $inv2] = $this->invoicedSale('500.00', [], 3, $customer);
        [, $void] = $this->invoicedSale('777.00');
        $this->postJson('/api/v1/invoices/'.$void['id'].'/void', ['reason' => 'Wrong customer', 'idempotency_key' => $this->key()])->assertOk();
        $this->pay($inv1['id'], '400.00')->assertCreated();
        $reversed = $this->pay($inv2['id'], '500.00')->assertCreated()->json('data');
        $this->postJson('/api/v1/payments/'.$reversed['id'].'/reverse', ['reason' => 'Bounced', 'idempotency_key' => $this->key()])->assertOk();

        $r = $this->report('receivables');
        $this->assertCount(1, $r->json('data.rows'));
        $row = $r->json('data.rows.0');
        $this->assertSame('Mama Ngozi', $row['customer']);
        $this->assertSame(2, $row['invoices']);
        $this->assertSame('1500.00', $row['invoiced']);
        $this->assertSame('400.00', $row['received'], 'a reversed payment does not count as received');
        $this->assertSame('1100.00', $row['outstanding']);
        $this->assertSame('1100.00', $r->json('data.summary.outstanding'));
    }

    public function test_contact_history_columns_follow_purchase_and_sale_permissions(): void
    {
        $customer = $this->customer('Buyer One');
        $this->invoicedSale('250.00', [], 3, $customer);

        $r = $this->report('contact_history');
        $this->assertSame('Buyer One', $r->json('data.rows.0.contact'));
        $this->assertSame(1, $r->json('data.rows.0.sales'));
        $this->assertSame('250.00', $r->json('data.rows.0.sales_total'));
        $this->assertSame(0, $r->json('data.rows.0.purchases'));
        $this->assertSame('250.00', $r->json('data.summary.sales_total'));

        // A manager holds all three permissions; the report never invents columns the viewer cannot see.
        $keys = array_column($r->json('data.columns'), 'key');
        $this->assertContains('purchase_total', $keys);
        $this->assertContains('sales_total', $keys);
    }

    // ------------------------------------------------------------------ work, health, breeding

    public function test_task_compliance_counts_visible_tasks_and_completion_rate(): void
    {
        $done = $this->task(['due_date' => $this->day(-2)])->assertCreated()->json('data.id');
        $this->task(['due_date' => $this->day(-1)])->assertCreated();
        $this->task(['due_date' => $this->day(3)])->assertCreated();
        $cancelled = $this->task(['due_date' => $this->day(-1)])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/tasks/'.$done.'/complete', ['idempotency_key' => $this->key()])->assertOk();
        $this->postJson('/api/v1/tasks/'.$cancelled.'/cancel', ['reason' => 'No longer needed', 'idempotency_key' => $this->key()])->assertOk();

        $r = $this->report('task_compliance', ['from' => $this->day(-5), 'to' => $this->day(5)]);
        $s = $r->json('data.summary');
        $this->assertSame(3, $s['total'], 'cancelled tasks are not counted as due');
        $this->assertSame(1, $s['completed']);
        $this->assertSame(1, $s['late'], 'completed after its due time');
        $this->assertSame(1, $s['overdue']);
        $this->assertSame(1, $s['upcoming']);
        $this->assertSame(1, $s['cancelled']);
        $this->assertSame('33.33', $s['completion_rate']);

        // A worker only sees the tasks that are theirs.
        $worker = $this->member(FarmRole::FarmWorker);
        $this->signInAs($worker);
        $this->assertSame(0, $this->report('task_compliance', ['from' => $this->day(-5), 'to' => $this->day(5)])->json('data.summary.total'));
    }

    public function test_health_treatment_counts_events_and_excludes_reversed(): void
    {
        $cycle = $this->layers(100);
        $payload = fn () => ['production_cycle_id' => $cycle, 'type' => 'vet_visit', 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'animals_affected' => 100,
            'details' => ['vet_name' => 'Dr Ade']];
        $this->postJson('/api/v1/health-records', $payload())->assertCreated();
        $gone = $this->postJson('/api/v1/health-records', $payload())->assertCreated()->json('data');
        $this->postJson('/api/v1/health-records/'.$gone['id'].'/reverse', ['reason' => 'Entered twice', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();

        $r = $this->report('health_treatment');
        $this->assertCount(1, $r->json('data.rows'));
        $this->assertSame('vet_visit', $r->json('data.rows.0.type'));
        $this->assertSame(1, $r->json('data.rows.0.events'));
        $this->assertSame(100, $r->json('data.rows.0.animals_affected'));
    }

    public function test_breeding_outcomes_show_net_live_and_lost_counts(): void
    {
        $cycle = $this->layers(100);
        $project = $this->postJson('/api/v1/breeding-projects', ['production_cycle_id' => $cycle, 'workflow' => 'incubation', 'start_date' => $this->day(-20), 'eggs_set' => 50, 'idempotency_key' => $this->key()])->assertCreated()->json('data');
        $first = $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes', ['live_count' => 30, 'loss_count' => 20, 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key()])->assertCreated()->json('data');
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes/'.$first['id'].'/reverse', ['reason' => 'Miscounted', 'recorded_at' => $this->at(4), 'idempotency_key' => $this->key()])->assertCreated();
        $this->postJson('/api/v1/breeding-projects/'.$project['id'].'/outcomes', ['live_count' => 37, 'loss_count' => 13, 'recorded_at' => $this->at(3), 'idempotency_key' => $this->key()])->assertCreated();

        $row = $this->rows('breeding_outcomes')[0];
        $this->assertSame($project['reference'], $row['reference']);
        $this->assertSame(37, $row['live_count']);
        $this->assertSame(13, $row['loss_count']);
        $this->assertSame(50, $row['eggs_set']);
    }

    // ------------------------------------------------------------------ isolation, shape, performance

    public function test_reports_never_include_another_farms_data(): void
    {
        [$otherOwner] = $this->otherFarm();
        $this->signInAs($otherOwner);
        $this->onPlan('farm-business', $otherOwner->currentFarm());
        $foreign = $this->layers(70);
        $this->mortality($foreign, 7);
        $this->income('9999.00');
        $this->signInAs($this->owner);

        $this->assertSame([], $this->rows('livestock_population'));
        $this->assertSame(0, $this->report('mortality')->json('data.summary.deaths'));
        $this->assertSame('0.00', $this->report('income_expense')->json('data.summary.income'));
        $this->report('livestock_population', ['production_cycle_id' => $foreign], 422)->assertJsonValidationErrors('production_cycle_id');
    }

    public function test_rows_are_paginated_while_the_summary_covers_every_row(): void
    {
        $a = $this->layers(10);
        $b = $this->layers(20);
        $c = $this->layers(30);
        $this->mortality($a, 1);
        $this->mortality($b, 2);
        $this->mortality($c, 3);

        $page = $this->report('livestock_population', ['per_page' => 2, 'page' => 2]);
        $this->assertCount(1, $page->json('data.rows'));
        $this->assertSame(3, $page->json('meta.total'));
        $this->assertSame(2, $page->json('meta.last_page'));
        $this->assertSame(-6, $page->json('data.summary.deaths'));
        $this->assertSame(54, $page->json('data.summary.closing'));
    }

    public function test_report_query_count_does_not_grow_with_the_number_of_cycles(): void
    {
        $count = function (string $code) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report($code);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $first = $this->layers(10);
        $this->mortality($first, 1);
        $this->eggs($first, 5);
        $codes = ['livestock_population', 'mortality', 'production_output', 'cycle_performance'];
        $baseline = array_map($count, $codes);
        for ($i = 0; $i < 5; $i++) {
            $c = $this->layers(10);
            $this->mortality($c, 1);
            $this->eggs($c, 5);
        }
        $this->assertSame($baseline, array_map($count, $codes), 'one aggregate query per source regardless of the number of cycles');
    }

    public function test_nothing_a_report_shows_is_stored(): void
    {
        $cycle = $this->layers(10);
        $this->mortality($cycle, 1);
        $before = collect(DB::select('SHOW TABLES'))->map(fn ($t) => array_values((array) $t)[0])->sort()->values()->all();
        $this->report('livestock_population');
        $this->assertSame([], array_filter($before, fn ($t) => str_contains($t, 'report_totals') || str_contains($t, 'report_rows')));
        $this->assertSame(0, DB::table('report_exports')->count(), 'running a report creates no export');
        $this->assertSame(1, FarmMembership::where('user_id', $this->owner->id)->count());
    }
}
