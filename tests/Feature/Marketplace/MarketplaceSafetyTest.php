<?php

namespace Tests\Feature\Marketplace;

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\MarketplaceContentReport;
use App\Models\MarketplaceDealReport;
use App\Models\MarketplaceReportEvent;
use App\Models\MarketplaceShop;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MarketplaceSafetyTest extends DealTestCase
{
    private function contentReport(string $reason = 'suspected_fraud'): string
    {
        return $this->signInAs($this->buyer)->postJson(self::SELLER.'/reports/listing/'.$this->slug, ['reason' => $reason, 'description' => 'Confidential complaint detail'])->assertCreated()->json('data.id');
    }

    private function transition(string $type, string $id, string $state, array $extra = [])
    {
        return $this->postJson(self::ADMIN."/reports/$type/$id/transition", $extra + ['status' => $state, 'reason' => 'Administrative review reason']);
    }

    public function test_content_duplicate_issues_and_closed_case_repetition(): void
    {
        $id = $this->contentReport();
        $this->postJson(self::SELLER.'/reports/listing/'.$this->slug, ['reason' => 'suspected_fraud', 'description' => 'New wording'])->assertOk()->assertJsonPath('data.id', $id);
        $this->contentReport('spam');
        $this->assertSame(2, MarketplaceContentReport::count());
        $this->assertSame('active', MarketplaceShop::find($this->shopId)->status->value);
        $this->getJson(self::PUBLIC.'/'.$this->slug)->assertOk();
        $this->signInAs($this->admin());
        $this->transition('content', $id, 'in_review')->assertOk();
        $this->transition('content', $id, 'dismissed')->assertOk();
        $second = $this->contentReport();
        $this->assertNotSame($id, $second);
        $this->assertSame(3, MarketplaceReportEvent::where('report_id', $id)->count());
    }

    public function test_deal_report_closure_allows_new_case_without_deal_mutation(): void
    {
        $deal = $this->dealId();
        $body = ['target' => 'deal', 'reason' => 'no_show'];
        $id = $this->dealAction('report', $deal, $body)->assertCreated()->json('data.id');
        $this->dealAction('report', $deal, $body)->assertOk()->assertJsonPath('data.id', $id);
        $this->dealAction('report', $deal, ['target' => 'deal', 'reason' => 'terms_changed'])->assertCreated();
        $before = $this->deal($deal)->getAttributes();
        $this->signInAs($this->admin());
        $this->transition('deal', $id, 'in_review')->assertOk();
        $this->transition('deal', $id, 'resolved')->assertOk();
        $this->assertSame($before, $this->deal($deal)->getAttributes());
        $new = $this->dealAction('report', $deal, $body)->assertCreated()->json('data.id');
        $this->assertNotSame($id, $new);
        $this->assertSame(3, MarketplaceDealReport::count());
    }

    public function test_required_reasons_state_machine_and_support_authorization(): void
    {
        $id = $this->contentReport();
        $this->getJson(self::ADMIN.'/summary')->assertForbidden();
        $this->signInAs($this->admin(PlatformRole::Support));
        $this->getJson(self::ADMIN.'/reports')->assertOk();
        $this->transition('content', $id, 'in_review')->assertForbidden();
        $this->signInAs($this->admin());
        $this->postJson(self::ADMIN."/reports/content/$id/transition", ['status' => 'in_review'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->transition('content', $id, 'resolved')->assertConflict();
        $this->transition('content', $id, 'in_review')->assertOk();
        $this->transition('content', $id, 'resolved')->assertOk();
        $this->getJson(self::ADMIN.'/summary')->assertOk()->assertJsonPath('data.reports.total.resolved', 1);
        $this->transition('content', $id, 'dismissed')->assertConflict();
        $this->assertSame(3, MarketplaceReportEvent::where('report_id', $id)->count());
    }

    public function test_summary_exposes_all_report_states_and_filters(): void
    {
        $id = $this->contentReport();
        $this->signInAs($this->admin());
        $this->getJson(self::ADMIN.'/summary')->assertOk()->assertJsonPath('data.reports.total.open', 1)->assertJsonPath('data.reports.total.in_review', 0)->assertJsonPath('data.reports.total.resolved', 0)->assertJsonPath('data.reports.total.dismissed', 0);
        $this->transition('content', $id, 'in_review')->assertOk();
        $this->getJson(self::ADMIN.'/summary')->assertJsonPath('data.reports.total.in_review', 1)->assertJsonPath('data.reports.total.open', 0);
        $this->getJson(self::ADMIN.'/reports?status=in_review&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->transition('content', $id, 'dismissed')->assertOk();
        $this->getJson(self::ADMIN.'/summary')->assertJsonPath('data.reports.total.dismissed', 1);
    }

    public function test_reporter_confidentiality_and_isolation(): void
    {
        $id = $this->contentReport();
        $this->signInAs($this->admin());
        $this->transition('content', $id, 'in_review')->assertOk();
        $this->getJson(self::ADMIN."/reports/content/$id")->assertOk()->assertJsonCount(2, 'data.history');
        $this->signInAs($this->buyer);
        $this->getJson(self::SELLER."/my/reports/content/$id")->assertOk()->assertJsonMissingPath('data.history')->assertJsonMissingPath('data.handled_by');
        $this->signInAs($this->sellerUser)->getJson(self::SELLER."/my/reports/content/$id")->assertNotFound();
        $this->getJson(self::SELLER.'/my/reports')->assertJsonPath('meta.total', 0);
        $this->assertStringNotContainsString('Confidential complaint', $this->getJson(self::PUBLIC.'/'.$this->slug)->getContent());
    }

    public function test_explicit_listing_enforcement_and_restore_reason(): void
    {
        $id = $this->contentReport();
        $this->signInAs($this->admin());
        $this->transition('content', $id, 'in_review')->assertOk();
        $this->transition('content', $id, 'resolved', ['enforcement_action' => 'restrict_listing', 'enforcement_id' => $this->fishListingId])->assertUnprocessable();
        $this->assertSame('in_review', MarketplaceContentReport::find($id)->status);
        $this->transition('content', $id, 'resolved', ['enforcement_action' => 'restrict_listing', 'enforcement_id' => $this->listingId])->assertOk();
        $this->getJson(self::PUBLIC.'/'.$this->slug)->assertNotFound();
        $this->signInAs($this->sellerUser)->postJson(self::SELLER."/shops/{$this->shopId}/listings/{$this->listingId}/publish")->assertConflict();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/listings/{$this->listingId}/lift-restriction")->assertUnprocessable();
        $this->postJson(self::ADMIN."/listings/{$this->listingId}/lift-restriction", ['reason' => 'Evidence checked'])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->getJson(self::PUBLIC.'/'.$this->slug)->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'platform.marketplace_report_resolved')->count());
    }

    public function test_suspension_preserves_existing_deal_and_financial_rows(): void
    {
        $deal = $this->dealId();
        $id = $this->dealAction('report', $deal, ['target' => 'other_party', 'reason' => 'suspected_fraud'])->assertCreated()->json('data.id');
        $before = $this->deal($deal)->getAttributes();
        $tables = ['sales', 'invoices', 'payments', 'finance_transactions', 'marketplace_shop_subscriptions', 'marketplace_promotions'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->signInAs($this->admin());
        $this->transition('deal', $id, 'in_review')->assertOk();
        $this->transition('deal', $id, 'resolved', ['enforcement_action' => 'suspend_shop', 'enforcement_id' => $this->shopId])->assertOk();
        $this->assertSame($before, $this->deal($deal)->getAttributes());
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count());
        }
        $this->dealAction('complete', $deal)->assertOk();
        $this->asSeller('complete', $deal)->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_shop_reports_and_validation_public_target_rules(): void
    {
        $slug = MarketplaceShop::find($this->shopId)->slug;
        $this->signInAs($this->buyer)->postJson(self::SELLER."/reports/shop/$slug", ['reason' => 'spam', 'description' => 'Unwanted messages'])->assertCreated();
        $this->postJson(self::SELLER.'/reports/listing/'.$this->slug, ['reason' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'description']);
        $this->postJson(self::SELLER.'/reports/listing/missing', ['reason' => 'spam', 'description' => 'Spam content'])->assertNotFound();
        $this->signInAs($this->admin())->postJson(self::ADMIN."/shops/{$this->shopId}/suspend", ['reason' => 'Safety review'])->assertOk();
        $this->signInAs($this->buyer)->postJson(self::SELLER."/reports/shop/$slug", ['reason' => 'spam', 'description' => 'Hidden target'])->assertNotFound();
    }

    public function test_offer_oversight_and_readonly_permissions(): void
    {
        $offer = $this->offerId();
        $this->signInAs($this->admin(PlatformRole::Support));
        $this->getJson(self::ADMIN.'/offers?offer_status=pending&shop_id='.$this->shopId)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(self::ADMIN."/offers/$offer")->assertOk()->assertJsonPath('data.contact', null)->assertJsonCount(1, 'data.history');
        $this->postJson(self::ADMIN."/offers/$offer/accept")->assertNotFound();
    }

    public function test_active_issue_database_uniqueness_and_append_only_history(): void
    {
        $id = $this->contentReport();
        $event = MarketplaceReportEvent::where('report_id', $id)->firstOrFail();
        try {
            $event->delete();
            $this->fail('History deletion allowed');
        } catch (\LogicException $e) {
            $this->assertSame('Report history is append-only.', $e->getMessage());
        }
        try {
            $event->update(['reason' => 'Changed']);
            $this->fail('History update allowed');
        } catch (\LogicException $e) {
            $this->assertSame('Report history is append-only.', $e->getMessage());
        }
        $this->expectException(QueryException::class);
        MarketplaceContentReport::create(MarketplaceContentReport::find($id)->only(['reporter_id', 'target_type', 'target_id', 'reason', 'status', 'open_slot']) + ['reference' => 'MRP-2099-00001']);
    }

    public function test_report_throttle_is_shared_by_content_and_deal_reports(): void
    {
        $deal = $this->dealId();
        $this->signInAs($this->buyer);
        for ($i = 0; $i < 20; $i++) {
            $this->postJson(self::SELLER.'/reports/listing/'.$this->slug, ['reason' => 'spam', 'description' => 'Report detail'])->assertStatus($i === 0 ? 201 : 200);
        }
        $this->postJson(self::SELLER.'/reports/listing/'.$this->slug, ['reason' => 'spam', 'description' => 'Report detail'])->assertStatus(429);
        $this->dealAction('report', $deal, ['target' => 'deal', 'reason' => 'other'])->assertStatus(429);
    }

    public function test_report_audits_are_never_farm_visible_and_original_complaint_is_immutable(): void
    {
        $deal = $this->dealId();
        $id = $this->dealAction('report', $deal, ['target' => 'deal', 'reason' => 'no_show'])->assertCreated()->json('data.id');
        $this->assertNull(AuditLog::where('action', 'marketplace.deal_reported')->firstOrFail()->farm_id);
        $report = MarketplaceDealReport::findOrFail($id);
        try {
            $report->update(['description' => 'Rewritten accusation']);
            $this->fail('Complaint modified');
        } catch (\LogicException $e) {
            $this->assertSame('Filed complaints are immutable.', $e->getMessage());
        }
        try {
            $report->delete();
            $this->fail('Complaint deleted');
        } catch (\LogicException $e) {
            $this->assertSame('Reports cannot be deleted.', $e->getMessage());
        }
    }

    public function test_resolution_enforcement_requires_explicit_action_and_review_deduplicates(): void
    {
        $id = $this->contentReport();
        $this->signInAs($this->admin());
        $this->transition('content', $id, 'in_review')->assertOk();
        $this->transition('content', $id, 'dismissed', ['enforcement_action' => 'suspend_shop', 'enforcement_id' => $this->shopId])->assertUnprocessable();
        $this->transition('content', $id, 'resolved', ['enforcement_id' => $this->shopId])->assertUnprocessable();
        $this->assertSame('active', MarketplaceShop::find($this->shopId)->status->value);
        $this->signInAs($this->buyer)->postJson(self::SELLER.'/reports/listing/'.$this->slug, ['reason' => 'suspected_fraud', 'description' => 'Same active issue'])->assertOk()->assertJsonPath('data.id', $id);
    }
}
