<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingEvent;

/** Writes the append-only lifecycle history of a listing (who changed its state, from what, to what, and why). */
class MarketplaceListingHistory
{
    public function record(MarketplaceListing $listing, string $actorKind, ?string $actorId, string $action, ?string $from, ?string $to, ?string $reason = null): MarketplaceListingEvent
    {
        return MarketplaceListingEvent::create([
            'listing_id' => $listing->id, 'actor_id' => $actorId, 'actor_kind' => $actorKind, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
        ]);
    }
}
