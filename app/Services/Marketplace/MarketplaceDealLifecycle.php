<?php

namespace App\Services\Marketplace;

use App\Enums\DealStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealEvent;
use App\Models\MarketplaceDealReport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiHttpException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * What the two parties can do to a deal once it exists. Every action locks the single deal row (nothing else), is idempotent, and is written to the
 * append-only deal history and the audit trail.
 *
 * Completion is SELF-REPORTED by each side and needs both: the deal becomes `completed` only when the buyer and an owner/manager of the shop have each
 * said so. Farmvest verifies nothing offline. Cancellation ends further contact access; it restores no stock and refunds nothing, because Farmvest
 * never held either. A report preserves the complaint and changes nothing about the deal's status.
 */
class MarketplaceDealLifecycle
{
    public const CANCEL_REASONS = ['changed_mind', 'seller_unavailable', 'no_agreement_on_terms', 'no_response', 'found_alternative', 'other'];

    public const REPORT_REASONS = ['no_show', 'misrepresented_product', 'terms_changed', 'abusive_behaviour', 'suspected_fraud', 'other'];

    public const REPORT_TARGETS = ['deal', 'other_party'];

    public function __construct(private MarketplaceShopService $shops, private AuditLogger $audit) {}

    // ------------------------------------------------------------------ actions

    /** One side reports that the deal was carried out. Repeating it is a no-op; the deal completes when both sides have. */
    public function complete(User $user, string $side, ?string $shopId, string $dealId): MarketplaceDeal
    {
        return DB::transaction(function () use ($user, $side, $shopId, $dealId) {
            $deal = $this->lockedFor($user, $side, $shopId, $dealId);
            if ($deal->status === DealStatus::Cancelled) {
                throw new ApiHttpException(409, 'deal_not_open', 'This deal was cancelled.', details: ['status' => $deal->status->value]);
            }
            $column = $side === 'buyer' ? 'buyer_completed_at' : 'seller_completed_at';
            if ($deal->status === DealStatus::Completed || $deal->{$column} !== null) {
                return $this->fresh($deal);
            }

            $deal->{$column} = now();
            if ($side === 'seller') {
                $deal->seller_completed_by = $user->id;
            }
            $deal->save();
            $this->event($deal, $side, $user->id, 'completion_confirmed', $deal->status->value, $deal->status->value);
            $this->auditDeal($deal, $user, 'marketplace.deal_completion_confirmed', ['side' => $side]);

            if ($deal->buyer_completed_at !== null && $deal->seller_completed_at !== null) {
                $deal->forceFill(['status' => DealStatus::Completed, 'completed_at' => now()])->save();
                $this->event($deal, 'system', null, 'completed', DealStatus::Accepted->value, DealStatus::Completed->value);
                $this->auditDeal($deal, null, 'marketplace.deal_completed', []);
            }

            return $this->fresh($deal);
        });
    }

    /** Either side cancels an ACTIVE deal with a reason code. Repeating it is a no-op; a completed deal cannot be cancelled. */
    public function cancel(User $user, string $side, ?string $shopId, string $dealId, string $reason, ?string $note): MarketplaceDeal
    {
        return DB::transaction(function () use ($user, $side, $shopId, $dealId, $reason, $note) {
            $deal = $this->lockedFor($user, $side, $shopId, $dealId);
            if ($deal->status === DealStatus::Cancelled) {
                return $this->fresh($deal);
            }
            if ($deal->status === DealStatus::Completed) {
                throw new ApiHttpException(409, 'deal_not_open', 'A completed deal cannot be cancelled.', details: ['status' => $deal->status->value]);
            }

            $deal->forceFill(['status' => DealStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by_side' => $side, 'cancelled_by' => $user->id, 'cancel_reason' => $reason, 'cancel_note' => $note])->save();
            $this->event($deal, $side, $user->id, 'cancelled', DealStatus::Accepted->value, DealStatus::Cancelled->value, $reason);
            $this->auditDeal($deal, $user, 'marketplace.deal_cancelled', ['side' => $side, 'reason' => $reason]);

            return $this->fresh($deal);
        });
    }

    /**
     * A party reports the deal or the other party. Allowed in ANY deal state (a buyer may report after a cancellation) and never changes the deal's status.
     * One active report per reporter, target and issue; closing a case allows later reports. `[$report, $created]`.
     *
     * @return array{0: MarketplaceDealReport, 1: bool}
     */
    public function report(User $user, string $side, ?string $shopId, string $dealId, string $target, string $reason, ?string $description): array
    {
        return MarketplaceReferences::locked('marketplace_deal_report', fn () => DB::transaction(function () use ($user, $side, $shopId, $dealId, $target, $reason, $description) {
            $deal = $this->lockedFor($user, $side, $shopId, $dealId);
            $subject = $target === 'deal' ? 'deal' : ($side === 'buyer' ? 'seller' : 'buyer');

            if ($existing = MarketplaceDealReport::where('deal_id', $deal->id)->where('reporter_id', $user->id)->where('target', $subject)->where('reason', $reason)->where('open_slot', 'O')->lockForUpdate()->first()) {
                return [$existing, false];
            }
            $report = new MarketplaceDealReport([
                'deal_id' => $deal->id, 'reporter_id' => $user->id, 'reporter_side' => $side, 'target' => $subject, 'reason' => $reason,
                'description' => $description, 'deal_status_at_report' => $deal->status->value,
            ]);
            $report->forceFill(['reference' => MarketplaceReferences::next('DRP', MarketplaceDealReport::class), 'status' => 'open', 'open_slot' => 'O'])->save();
            app(MarketplaceReportService::class)->event('deal', $report, $user, null, 'open', null);
            $this->event($deal, $side, $user->id, 'reported', $deal->status->value, $deal->status->value, $reason);
            // A farm's audit view must not disclose a confidential complaint or its reporter to the reported shop.
            $this->audit->record(null, $user->id, 'marketplace.deal_reported', 'marketplace_deal', $deal->id, $deal->reference, ['report_id' => $report->id, 'target' => $subject, 'reason' => $reason]);

            return [$report, true];
        }));
    }

    // ------------------------------------------------------------------ internals

    /**
     * The deal row, locked, for a party that may act on it. A buyer reaches only their own deals; a seller only their shop's, and only with `deal.respond`
     * (owner, manager). Anything else is a 404 (non-member / not the buyer) or 403 (member without the permission).
     */
    private function lockedFor(User $user, string $side, ?string $shopId, string $dealId): MarketplaceDeal
    {
        if ($side === 'seller') {
            $shop = $this->shops->memberShop($user, (string) $shopId);
            if (! $user->can('respondToDeals', $shop)) {
                throw new AuthorizationException;
            }

            return MarketplaceDeal::where('shop_id', $shop->id)->lockForUpdate()->findOrFail($dealId);
        }

        return MarketplaceDeal::where('buyer_id', $user->id)->lockForUpdate()->findOrFail($dealId);
    }

    private function fresh(MarketplaceDeal $deal): MarketplaceDeal
    {
        return $deal->load(['listing', 'shop', 'buyer', 'offer', 'confirmation', 'events', 'reports']);
    }

    private function event(MarketplaceDeal $deal, string $kind, ?string $actorId, string $action, ?string $from, ?string $to, ?string $code = null): void
    {
        MarketplaceDealEvent::create(['deal_id' => $deal->id, 'actor_kind' => $kind, 'actor_id' => $actorId, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'code' => $code]);
    }

    /** @param  array<string, mixed>  $changes */
    private function auditDeal(MarketplaceDeal $deal, ?User $actor, string $action, array $changes): void
    {
        $deal->loadMissing('shop');
        $this->audit->record($deal->shop->farm_id, $actor?->id, $action, 'marketplace_deal', $deal->id, $deal->reference, $changes + ['listing_id' => $deal->listing_id]);
    }
}
