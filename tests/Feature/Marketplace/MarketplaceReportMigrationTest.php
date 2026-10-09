<?php

namespace Tests\Feature\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Isolated in-memory legacy schema: upgrading must preserve filed complaints and their audit records. */
class MarketplaceReportMigrationTest extends TestCase
{
    public function test_legacy_reports_survive_upgrade_and_rollback_refuses_to_discard_repeat_cases(): void
    {
        config(['database.connections.phase27_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('phase27_migration');
        try {
            Schema::create('users', fn (Blueprint $t) => $t->uuid('id')->primary());
            Schema::create('marketplace_deals', fn (Blueprint $t) => $t->uuid('id')->primary());
            Schema::create('marketplace_deal_reports', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('reference');
                $t->foreignUuid('deal_id')->constrained('marketplace_deals');
                $t->foreignUuid('reporter_id')->constrained('users');
                $t->string('target', 10);
                $t->string('reason', 30);
                $t->text('description');
                $t->string('status', 10)->default('open');
                $t->timestamps();
                $t->unique(['deal_id', 'reporter_id', 'target'], 'deal_reports_one_per_reporter_target');
            });
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('farm_id')->nullable();
                $t->string('action');
            });
            $user = (string) Str::uuid7();
            $deal = (string) Str::uuid7();
            $id = (string) Str::uuid7();
            DB::table('users')->insert(['id' => $user]);
            DB::table('marketplace_deals')->insert(['id' => $deal]);
            $legacy = ['id' => $id, 'reference' => 'DRP-2026-00001', 'deal_id' => $deal, 'reporter_id' => $user, 'target' => 'seller', 'reason' => 'no_show', 'description' => 'Original complaint', 'status' => 'open', 'created_at' => '2026-10-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00'];
            DB::table('marketplace_deal_reports')->insert($legacy);
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid7(), 'farm_id' => (string) Str::uuid7(), 'action' => 'marketplace.deal_reported']);
            $migration = require base_path('database/migrations/2026_10_24_100000_create_marketplace_report_workflows.php');
            $migration->up();
            $row = (array) DB::table('marketplace_deal_reports')->where('id', $id)->first();
            foreach ($legacy as $key => $value) {
                $this->assertSame($value, $row[$key], $key);
            }
            $this->assertSame('O', $row['open_slot']);
            $event = DB::table('marketplace_report_events')->first();
            $this->assertSame($id, $event->report_id);
            $this->assertSame($legacy['created_at'], $event->created_at);
            $this->assertSame($user, $event->actor_id);
            $this->assertNull(DB::table('audit_logs')->value('farm_id'));
            $migration->down();
            $this->assertSame('Original complaint', DB::table('marketplace_deal_reports')->value('description'));
            $migration->up();
            DB::table('marketplace_deal_reports')->insert(array_replace($legacy, ['id' => (string) Str::uuid7(), 'reference' => 'DRP-2026-00002', 'status' => 'resolved', 'open_slot' => null]));
            try {
                $migration->down();
                $this->fail('Rollback discarded repeat cases');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('repeat deal reports', $e->getMessage());
            }
            $this->assertSame(2, DB::table('marketplace_deal_reports')->count());
            $this->assertTrue(Schema::hasTable('marketplace_report_events'));
        } finally {
            DB::setDefaultConnection('mysql');
            DB::purge('phase27_migration');
        }
    }
}
