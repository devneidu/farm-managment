<?php

namespace Tests\Feature\Audit;

use App\Enums\FarmRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\FarmInvitationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reports\ReportsFixtures;
use Tests\Feature\Team\TeamTestCase;

class AuditTest extends TeamTestCase
{
    use ReportsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->onPlan('farm-business');
        $this->bootClock();
        $this->signInAs($this->owner);
    }

    private function audit(array $query = []): array
    {
        return $this->getJson('/api/v1/audit'.($query ? '?'.http_build_query($query) : ''))->assertOk()->json('data');
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(string $action, array $query = []): array
    {
        return array_values(array_filter($this->audit($query + ['per_page' => 100]), fn ($e) => $e['action'] === $action));
    }

    private function actions(array $query = []): array
    {
        return array_column($this->audit($query + ['per_page' => 100]), 'action');
    }

    // ------------------------------------------------------------------ who, what, when, which resource, which request

    public function test_an_entry_says_who_did_what_when_to_which_resource_and_in_which_request(): void
    {
        $worker = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $cycle = $this->layers(100);
        $this->signInAs($worker);
        $response = $this->withHeaders(['X-Request-Id' => 'audit-request-0001'])->postJson('/api/v1/records', ['production_cycle_id' => $cycle, 'type' => 'mortality', 'recorded_at' => $this->at(5), 'idempotency_key' => $this->key(), 'details' => ['quantity' => 2, 'cause' => 'Disease']])->assertCreated();
        $record = $response->json('data');
        $this->assertSame('audit-request-0001', $response->headers->get('X-Request-Id'));

        $this->signInAs($this->owner);
        $entry = $this->entries('record.created')[0];
        $this->assertSame('operational_record', $entry['resource']['type']);
        $this->assertSame($record['id'], $entry['resource']['id']);
        $this->assertSame('mortality', $entry['resource']['label']);
        $this->assertSame(['id' => $worker->id, 'name' => 'Wale Worker'], $entry['actor']);
        $this->assertSame('audit-request-0001', $entry['request_id']);
        $this->assertSame(now()->utc()->toISOString(), $entry['performed_at'], 'when the action happened in the system');
        $this->assertSame(now()->utc()->subHours(5)->toISOString(), $entry['recorded_at'], 'the business time is kept separately');
        $this->assertNotSame($entry['performed_at'], $entry['recorded_at']);
        $this->assertSame($cycle, $entry['production_cycle']['id']);
        $this->assertNull($entry['changes']);

        $byRequest = $this->audit(['request_id' => 'audit-request-0001']);
        $this->assertCount(1, $byRequest);
        $this->assertSame($record['id'], $byRequest[0]['resource']['id']);
    }

    public function test_reversals_and_corrections_are_visible_and_point_at_the_original(): void
    {
        $cycle = $this->layers(100);
        $record = $this->mortality($cycle, 4);
        $this->reverseRecord($record['id']);

        $history = $this->audit(['resource_id' => $record['id']]);
        $this->assertEqualsCanonicalizing(['record.created', 'record.reversed'], array_column($history, 'action'));
        $reversed = collect($history)->firstWhere('action', 'record.reversed');
        $this->assertSame($record['id'], $reversed['resource']['id'], 'the resource is the original record');
        $this->assertNotNull($reversed['related_id']);
        $this->assertNotSame($record['id'], $reversed['related_id'], 'related_id is the reversing row');
        $this->assertSame('mortality', $reversed['resource']['label']);
        $this->assertSame($this->owner->id, $reversed['actor']['id']);

        // A wrong income entry: recorded, then reversed - both are visible, with the reason not leaking any amount.
        $income = $this->income('777.77');
        $this->postJson('/api/v1/finance/transactions/'.$income['id'].'/reverse', ['reason' => 'Mistake', 'idempotency_key' => $this->key()])->assertCreated();
        $finance = $this->audit(['resource_id' => $income['id']]);
        $this->assertEqualsCanonicalizing(['finance.recorded', 'finance.reversed'], array_column($finance, 'action'));
    }

    public function test_health_and_other_module_events_appear_under_their_own_actions(): void
    {
        $cycle = $this->layers(100);
        $health = $this->postJson('/api/v1/health-records', ['production_cycle_id' => $cycle, 'type' => 'vet_visit', 'recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'details' => ['vet_name' => 'Dr Ade']])->assertCreated()->json('data');
        $this->postJson('/api/v1/health-records/'.$health['id'].'/reverse', ['reason' => 'Wrong flock', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertCreated();

        $feed = $this->item('feed', 'kg');
        $loc = $this->store();
        $this->postJson('/api/v1/purchases', ['recorded_at' => $this->at(2), 'idempotency_key' => $this->key(), 'items' => [['kind' => 'stock', 'inventory_item_id' => $feed, 'storage_location_id' => $loc, 'components' => [['quantity' => '10', 'unit' => 'kg']], 'amount' => '500']]])->assertCreated();
        [$sale, $invoice] = $this->invoicedSale('1200.00');
        $payment = $this->pay($invoice['id'], '200.00')->assertCreated()->json('data');
        $this->postJson('/api/v1/payments/'.$payment['id'].'/reverse', ['reason' => 'Bounced', 'idempotency_key' => $this->key()])->assertOk();
        $this->postJson('/api/v1/invoices/'.$invoice['id'].'/void', ['reason' => 'Re-issue', 'idempotency_key' => $this->key()])->assertOk();
        $this->postJson('/api/v1/sales/'.$sale['id'].'/cancel', ['reason' => 'Returned', 'recorded_at' => $this->at(1), 'idempotency_key' => $this->key()])->assertOk();
        $task = $this->task()->assertCreated()->json('data');
        $this->postJson('/api/v1/tasks/'.$task['id'].'/complete', ['idempotency_key' => $this->key()])->assertOk();
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $this->today(), 'reason' => 'Done'])->assertOk();

        $actions = $this->actions();
        foreach (['health_record.created', 'health_record.reversed', 'purchase.created', 'sale.created', 'sale.cancelled', 'invoice.issued', 'invoice.voided', 'payment.recorded', 'payment.reversed', 'task.completed',
            'production_cycle.created', 'production_cycle.closed', 'inventory.stock_in', 'subscription.plan_changed'] as $expected) {
            $this->assertContains($expected, $actions, "$expected should be in the audit trail");
        }
    }

    public function test_side_effect_movements_are_not_listed_twice(): void
    {
        $cycle = $this->layers(100);
        $loc = $this->store();
        $feed = $this->item('feed', 'kg');
        $this->receive($feed, $loc, '100', 'kg');
        $this->record($cycle, 'feed_use', ['feed_name' => 'Mash', 'components' => [['quantity' => '10', 'unit' => 'kg']], 'inventory' => ['item_id' => $feed, 'storage_location_id' => $loc]], 3)->assertCreated();

        $this->assertCount(1, $this->entries('inventory.stock_in'), 'only the standalone stock-in');
        $this->assertCount(0, $this->entries('inventory.stock_out'), 'the feed-use stock-out is part of the feed_use record');
        $this->assertCount(1, array_filter($this->entries('record.created'), fn ($e) => $e['resource']['label'] === 'feed_use'));
    }

    // ------------------------------------------------------------------ privileged actions

    public function test_team_and_settings_changes_are_audited_without_secrets(): void
    {
        $this->postJson('/api/v1/farm/invitations', ['email' => 'new.hire@example.com', 'role' => 'farm_worker'])->assertCreated();
        $member = $this->member(FarmRole::FarmWorker, null, 'Wale Worker');
        $membership = $this->membershipOf($member);
        $this->patchJson('/api/v1/farm/members/'.$membership->id, ['role' => 'finance'])->assertOk();
        $this->patchJson('/api/v1/farm', ['name' => 'Green Acres Ltd'])->assertOk();
        $this->deleteJson('/api/v1/farm/members/'.$membership->id)->assertOk();

        $invited = $this->entries('team.invitation_sent')[0];
        $this->assertSame('invitation', $invited['resource']['type']);
        $this->assertSame('new.hire@example.com', $invited['resource']['label']);
        $this->assertSame(['role' => 'farm_worker'], (array) $invited['changes']);
        $this->assertSame($this->owner->id, $invited['actor']['id']);
        $this->assertNotNull($invited['request_id']);
        $this->assertSame('127.0.0.1', $invited['ip_address']);

        $role = $this->entries('team.member_role_changed')[0];
        $this->assertEquals(['from' => 'farm_worker', 'to' => 'finance'], (array) $role['changes']);
        $this->assertSame($membership->id, $role['resource']['id']);
        $this->assertSame('Wale Worker', $role['resource']['label']);

        $settings = $this->entries('farm.settings_updated')[0];
        $this->assertSame(['name'], $settings['changes']['fields']);
        $this->assertSame('Green Acres Ltd', $settings['resource']['label']);
        $this->assertSame('team.member_removed', $this->entries('team.member_removed')[0]['action']);

        $raw = $this->getJson('/api/v1/audit?per_page=100')->getContent();
        foreach (['token', 'hash', 'password', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($raw), "audit output must not mention $forbidden");
        }
        $this->assertSame(0, AuditLog::where('changes', 'like', '%token%')->count());
    }

    public function test_accepting_an_invitation_is_audited_with_the_accepting_user_as_actor(): void
    {
        $invited = User::factory()->create(['email' => 'joiner@example.com', 'email_verified_at' => now(), 'onboarded_at' => now()]);
        $token = null;
        Notification::fake();
        $this->postJson('/api/v1/farm/invitations', ['email' => 'joiner@example.com', 'role' => 'vet'])->assertCreated();
        Notification::assertSentOnDemand(FarmInvitationNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->signInAs($invited);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token])->assertSuccessful();

        $this->signInAs($this->owner);
        $accepted = $this->entries('team.invitation_accepted')[0];
        $this->assertSame($invited->id, $accepted['actor']['id']);
        $this->assertSame('vet', $accepted['changes']['role']);
        $this->assertStringNotContainsString($token, $this->getJson('/api/v1/audit?per_page=100')->getContent());
    }

    public function test_exports_are_audited_when_requested_and_when_downloaded(): void
    {
        Storage::fake('local');
        $export = $this->postJson('/api/v1/reports/exports', ['report' => 'mortality', 'format' => 'csv', 'filters' => ['from' => $this->day(-7)], 'idempotency_key' => $this->key()])->assertStatus(202)->json('data');
        $this->get('/api/v1/reports/exports/'.$export['id'].'/download')->assertOk();

        $requested = $this->entries('report.export_requested')[0];
        $this->assertSame('report_export', $requested['resource']['type']);
        $this->assertSame($export['id'], $requested['resource']['id']);
        $this->assertSame('mortality', $requested['resource']['label']);
        $this->assertSame('csv', $requested['changes']['format']);
        $this->assertSame($this->day(-7), $requested['changes']['filters']['from']);
        $downloaded = $this->entries('report.export_downloaded')[0];
        $this->assertSame($this->owner->id, $downloaded['actor']['id']);
        $this->assertSame($export['id'], $downloaded['resource']['id']);
        $this->assertCount(2, $this->audit(['resource_id' => $export['id']]));
    }

    // ------------------------------------------------------------------ permission and isolation

    public function test_audit_is_restricted_to_roles_with_audit_view(): void
    {
        $this->layers(10);
        foreach ([FarmRole::FarmWorker, FarmRole::Vet, FarmRole::Finance] as $role) {
            $this->signInAs($this->member($role));
            $this->getJson('/api/v1/audit')->assertForbidden()->assertJsonPath('code', 'forbidden');
        }
        $this->signInAs($this->member(FarmRole::Manager));
        $this->assertNotEmpty($this->audit());

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/audit')->assertUnauthorized();
    }

    public function test_audit_never_shows_another_farms_activity(): void
    {
        [$otherOwner, $otherFarm] = $this->otherFarm();
        $this->onPlan('farm-business', $otherFarm);
        $this->signInAs($otherOwner);
        $foreign = $this->layers(70);
        $record = $this->mortality($foreign, 3);
        $this->income('4242.42');
        $this->signInAs($this->owner);
        $this->layers(10);

        $mine = $this->audit(['per_page' => 100]);
        $this->assertNotEmpty($mine);
        $raw = json_encode($mine);
        $this->assertStringNotContainsString($foreign, $raw);
        $this->assertStringNotContainsString($record['id'], $raw);
        $this->assertSame([], $this->audit(['resource_id' => $record['id']]), 'another farm\'s id returns nothing, not an error that reveals it exists');
        $this->assertSame([], $this->audit(['actor_id' => $otherOwner->id]));
        $this->assertSame([], $this->audit(['production_cycle_id' => $foreign]));
        $this->assertNotContains('finance.recorded', $this->actions());

        $this->signInAs($otherOwner);
        $this->assertStringNotContainsString($this->owner->id, json_encode($this->audit(['per_page' => 100])));
    }

    public function test_amounts_and_record_contents_are_never_part_of_an_entry(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 4, 2, 'Mysterious wasting disease');
        $this->income('123456.78');
        $this->invoicedSale('98765.43');
        $this->postJson('/api/v1/production-cycles/'.$cycle.'/close', ['end_date' => $this->today(), 'reason' => 'Private closing reason'])->assertOk();

        $raw = $this->getJson('/api/v1/audit?per_page=100')->getContent();
        foreach (['123456.78', '98765.43', 'Mysterious wasting', 'Private closing reason'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    // ------------------------------------------------------------------ filters, ordering, immutability

    public function test_filters_use_farm_local_days_and_entries_are_newest_first(): void
    {
        // 23:30 UTC on the 14th is 00:30 on the 15th in Lagos.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $cycle = $this->layers(100);
        $early = $this->mortality($cycle, 1);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:30:00', 'UTC'));
        $late = $this->mortality($cycle, 2, 1);

        $ids = fn (array $q) => array_column(array_column($this->audit($q + ['action' => 'record.created']), 'resource'), 'id');
        $this->assertSame([$late['id']], $ids(['from' => '2026-10-15', 'to' => '2026-10-15']));
        $this->assertSame([$early['id']], $ids(['from' => '2026-10-14', 'to' => '2026-10-14']));
        $this->assertSame([$late['id'], $early['id']], $ids([]), 'newest first');
        $this->assertSame([$late['id'], $early['id']], $ids(['from' => '2026-10-14', 'to' => '2026-10-15']));
        $this->assertSame([], $ids(['from' => '2026-10-16']));
        $this->getJson('/api/v1/audit?from=2026-10-16&to=2026-10-01')->assertStatus(422);
    }

    public function test_filters_by_actor_resource_type_action_and_pagination(): void
    {
        $manager = $this->member(FarmRole::Manager, null, 'Mo Manager');
        $cycle = $this->layers(100);
        $this->mortality($cycle, 1);
        $this->signInAs($manager);
        $this->mortality($cycle, 2);
        $this->income('10.00');
        $this->signInAs($this->owner);

        $this->assertSame(['record.created'], array_unique(array_column($this->audit(['actor_id' => $manager->id, 'action' => 'record.created']), 'action')));
        $this->assertSame([$manager->id], array_unique(array_column(array_column($this->audit(['actor_id' => $manager->id]), 'actor'), 'id')));
        $this->assertSame(['finance_transaction'], array_unique(array_column(array_column($this->audit(['resource_type' => 'finance_transaction']), 'resource'), 'type')));

        $page = $this->getJson('/api/v1/audit?per_page=2&page=2')->assertOk();
        $this->assertCount(2, $page->json('data'));
        $this->assertSame(2, $page->json('meta.current_page'));
        $this->assertGreaterThan(4, $page->json('meta.total'));
        $this->getJson('/api/v1/audit?per_page=500')->assertStatus(422);
        $this->getJson('/api/v1/audit?actor_id=nope')->assertStatus(422);
    }

    public function test_the_audit_log_table_is_append_only_and_farm_scoped(): void
    {
        $this->patchJson('/api/v1/farm', ['name' => 'Renamed Farm'])->assertOk();
        $log = AuditLog::first();
        $this->assertSame($this->farm->id, $log->farm_id);
        try {
            $log->forceFill(['action' => 'tampered'])->save();
            $this->fail('audit logs must not be updatable');
        } catch (\LogicException) {
            $this->assertSame('farm.settings_updated', $log->fresh()->action);
        }
        $this->expectException(\LogicException::class);
        $log->delete();
    }

    public function test_the_audit_is_built_without_a_second_copy_of_operational_history(): void
    {
        $cycle = $this->layers(100);
        $this->mortality($cycle, 3);
        $this->income('5.00');
        $this->invoicedSale('50.00');

        $this->assertSame(0, AuditLog::count(), 'ledger events are read in place, not duplicated into audit_logs');
        $this->assertContains('record.created', $this->actions());
        $this->assertContains('finance.recorded', $this->actions());
        $this->assertContains('sale.created', $this->actions());
        $this->assertSame(['audit_logs'], array_values(array_filter(array_map(fn ($t) => array_values((array) $t)[0], DB::select('SHOW TABLES')), fn ($t) => str_contains($t, 'audit'))));
    }

    public function test_audit_query_count_is_constant_for_a_page(): void
    {
        $cycle = $this->layers(100);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/audit?per_page=50')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $this->mortality($cycle, 1);
        $before = $count();
        for ($i = 0; $i < 8; $i++) {
            $this->mortality($cycle, 1, 2 + $i);
            $this->income((string) (10 + $i).'.00');
        }
        $this->assertSame($before, $count());
    }
}
