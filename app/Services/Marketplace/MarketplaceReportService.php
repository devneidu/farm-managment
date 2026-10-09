<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceContentReport;
use App\Models\MarketplaceDealReport;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceReportEvent;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\PlatformAudit;
use App\Support\Api\ApiHttpException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Shared triage for existing deal complaints and new content complaints. Only explicit admin decisions can enforce. */
class MarketplaceReportService
{
    public const STATES = ['open', 'in_review', 'dismissed', 'resolved'];

    public const CONTENT_REASONS = ['suspected_fraud', 'misrepresented_product', 'prohibited_content', 'abusive_behaviour', 'spam', 'other'];

    public function __construct(private PlatformAudit $audit, private MarketplaceModerationService $shops, private MarketplaceListingModerationService $listings) {}

    public function file(User $user, string $type, string $slug, array $data): array
    {
        return MarketplaceReferences::locked('marketplace_content_report', fn () => DB::transaction(function () use ($user, $type, $slug, $data) {
            $target = ($type === 'shop' ? MarketplaceShop::public() : MarketplaceListing::public())->where('slug', $slug)->firstOrFail();
            $existing = MarketplaceContentReport::where('reporter_id', $user->id)->where('target_type', $type)->where('target_id', $target->id)->where('reason', $data['reason'])->where('open_slot', 'O')->lockForUpdate()->first();
            if ($existing) {
                return [$existing, false];
            }
            $report = MarketplaceContentReport::create($data + ['reference' => MarketplaceReferences::next('MRP', MarketplaceContentReport::class), 'reporter_id' => $user->id, 'target_type' => $type, 'target_id' => $target->id, 'status' => 'open', 'open_slot' => 'O']);
            $this->event('content', $report, $user, null, 'open', null);
            app(AuditLogger::class)->record(null, $user->id, 'marketplace.content_reported', 'marketplace_content_report', $report->id, $report->reference, ['target_type' => $type, 'target_id' => $target->id, 'reason' => $data['reason']]);

            return [$report, true];
        }));
    }

    public function query(string $type)
    {
        return match ($type) {
            'content' => MarketplaceContentReport::query(),
            'deal' => MarketplaceDealReport::query(),
            default => abort(404),
        };
    }

    public function show(string $type, string $id, ?User $reporter = null): Model
    {
        return $this->query($type)->when($reporter, fn ($q) => $q->where('reporter_id', $reporter->id))->findOrFail($id);
    }

    public function transition(User $admin, string $type, string $id, array $data): Model
    {
        if (! $admin->platformRole()?->canWrite()) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($admin, $type, $id, $data) {
            $r = $this->query($type)->whereKey($id)->lockForUpdate()->firstOrFail();
            $from = $r->status;
            $to = $data['status'];
            if (! (($from === 'open' && $to === 'in_review') || ($from === 'in_review' && in_array($to, ['dismissed', 'resolved'], true)))) {
                throw new ApiHttpException(409, 'invalid_report_state', 'Review an open report before closing it; closed reports are final.');
            }
            $action = $data['enforcement_action'] ?? null;
            if ($action === null && isset($data['enforcement_id'])) {
                throw new ApiHttpException(422, 'invalid_enforcement', 'An enforcement target requires an explicit action.');
            }
            $enforcementType = null;
            if ($action !== null) {
                if ($to !== 'resolved') {
                    throw new ApiHttpException(422, 'invalid_enforcement', 'Enforcement must be an explicit resolution action.');
                }
                $enforcementType = $action === 'suspend_shop' ? 'shop' : 'listing';
                $targetId = $data['enforcement_id'];
                $shopId = $type === 'deal' ? $r->deal->shop_id : ($r->target_type === 'shop' ? $r->target_id : MarketplaceListing::withTrashed()->findOrFail($r->target_id)->shop_id);
                $listingId = $type === 'deal' ? $r->deal->listing_id : ($r->target_type === 'listing' ? $r->target_id : null);
                if ($targetId !== ($enforcementType === 'shop' ? $shopId : $listingId)) {
                    throw new ApiHttpException(422, 'unrelated_enforcement_target', 'The enforcement target must belong to this complaint.');
                }
                if ($action === 'suspend_shop') {
                    $this->shops->suspend($admin, $targetId, $data['reason']);
                } else {
                    $this->listings->restrict($admin, $targetId, $data['reason']);
                }
            }
            $closed = in_array($to, ['resolved', 'dismissed'], true);
            $r->forceFill(['status' => $to, 'handled_by' => $admin->id, 'outcome_reason' => $closed ? $data['reason'] : null, 'closed_at' => $closed ? now() : null, 'open_slot' => $closed ? null : 'O'])->save();
            $this->event($type, $r, $admin, $from, $to, $data['reason'], $enforcementType, $data['enforcement_id'] ?? null, $action);
            $this->audit->record($admin, 'platform.marketplace_report_'.$to, 'marketplace_'.$type.'_report', $r->id, $r->reference, ['status' => $from], ['status' => $to], ['reason' => $data['reason'], 'enforcement_action' => $action]);

            return $r;
        });
    }

    public function event(string $type, Model $r, User $actor, ?string $from, string $to, ?string $reason, ?string $enforcementType = null, ?string $enforcementId = null, ?string $action = null): void
    {
        MarketplaceReportEvent::create(['report_type' => $type, 'report_id' => $r->id, 'actor_id' => $actor->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'enforcement_type' => $enforcementType, 'enforcement_id' => $enforcementId, 'enforcement_action' => $action]);
    }

    public function serialize(string $type, Model $r, bool $admin = false): array
    {
        $out = $r->only(['id', 'reference', 'reason', 'description', 'status', 'outcome_reason', 'closed_at', 'created_at']);
        $out['created_at'] = $r->created_at->toIso8601String();
        $out['closed_at'] = $r->closed_at?->toIso8601String();
        $out += ['type' => $type, 'target' => $type === 'deal' ? ['type' => $r->target, 'id' => $r->deal_id] : ['type' => $r->target_type, 'id' => $r->target_id]];
        if ($admin) {
            $out['reporter_id'] = $r->reporter_id;
            $out['handled_by'] = $r->handled_by;
            $out['history'] = MarketplaceReportEvent::where('report_type', $type)->where('report_id', $r->id)->orderBy('created_at')->orderBy('id')->get()->map->only(['id', 'actor_id', 'from_status', 'to_status', 'reason', 'enforcement_type', 'enforcement_id', 'enforcement_action', 'created_at'])->all();
        }

        return $out;
    }
}
