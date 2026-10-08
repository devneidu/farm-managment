<?php

namespace App\Services\Marketplace;

use App\Enums\ConfirmationStatus;
use App\Models\MarketplaceDealConfirmation;
use App\Models\MarketplacePurchaseIntent;
use App\Services\Audit\AuditLogger;

/**
 * The single place a seller confirmation leaves `awaiting_buyer`. Callers hold the locks and run inside a transaction; a move on a confirmation that
 * is no longer open is a no-op, clears `open_slot` (so the intent can be confirmed afresh) and is audited.
 */
class MarketplaceConfirmationLifecycle
{
    public const VOID_INTENT_CHANGED = 'intent_changed';

    public const VOID_LISTING_CHANGED = 'listing_changed';

    public function __construct(private AuditLogger $audit) {}

    public function settle(MarketplaceDealConfirmation $c, ConfirmationStatus $to, string $actorKind, ?string $actorId, ?string $code = null): bool
    {
        if ($c->status !== ConfirmationStatus::AwaitingBuyer) {
            return false;
        }
        $c->forceFill(['status' => $to, 'open_slot' => null, 'settled_at' => now(), 'void_reason' => $to === ConfirmationStatus::Voided ? $code : null])->save();
        $c->loadMissing('shop');
        $this->audit->record($c->shop->farm_id, $actorKind === 'system' ? null : $actorId, 'marketplace.deal_confirmation_'.$to->value, 'marketplace_deal_confirmation', $c->id, $c->reference,
            array_filter(['intent_id' => $c->intent_id, 'listing_id' => $c->listing_id, 'code' => $code]));

        return true;
    }

    /** The buyer changed their interest: whatever the seller confirmed no longer matches it. Caller holds the intent lock. */
    public function voidOpenFor(MarketplacePurchaseIntent $intent): void
    {
        MarketplaceDealConfirmation::where('intent_id', $intent->id)->where('status', ConfirmationStatus::AwaitingBuyer->value)->lockForUpdate()->get()
            ->each(fn (MarketplaceDealConfirmation $c) => $this->settle($c, ConfirmationStatus::Voided, 'system', null, self::VOID_INTENT_CHANGED));
    }
}
