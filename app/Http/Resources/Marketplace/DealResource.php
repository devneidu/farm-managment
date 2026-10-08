<?php

namespace App\Http\Resources\Marketplace;

use App\Enums\DealStatus;
use App\Enums\ListingStatus;
use App\Enums\ShopStatus;
use App\Models\MarketplaceDeal;
use App\Services\Marketplace\MarketplaceProductCatalogue;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A deal summary for the buyer (`audience: buyer`), the shop's members (`seller`) or a platform admin (`admin`). It carries the FROZEN terms and NEVER any
 * private contact detail - not the shop's phone, WhatsApp, email or address, and not the buyer's email or phone; those are read through the audited contact
 * endpoint. The seller sees the buyer by display name only. Completion is always labelled self-reported.
 *
 * @property MarketplaceDeal $resource
 */
class DealResource extends JsonResource
{
    public const NOTICE = 'This is a summary of terms the buyer and seller agreed between themselves. Farmvest only connects them: it does not collect payment, hold funds, deliver goods, reserve stock or guarantee the outcome. Payment and transport are arranged directly.';

    public static $wrap = null;

    private string $audience = 'buyer';

    private bool $detailed = false;

    private bool $mayAct = true;

    public function audience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /** Seller audience only: whether the viewer holds `deal.respond` (drives the `can` flags). */
    public function mayAct(bool $mayAct): static
    {
        $this->mayAct = $mayAct;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $d = $this->resource;
        $open = $d->status === DealStatus::Accepted;
        $live = ! $d->listing->trashed() && $d->listing->status === ListingStatus::Published && $d->shop->status === ShopStatus::Active;
        $side = $this->audience === 'admin' ? null : $this->audience;
        $own = $side === 'buyer' ? $d->buyer_completed_at : $d->seller_completed_at;
        $acts = $this->audience === 'admin' ? false : ($this->audience === 'buyer' || $this->mayAct);

        $out = [
            'id' => $d->id, 'reference' => $d->reference, 'status' => $d->status->value, 'terms_source' => $d->terms_source,
            'source' => $d->terms_source === 'accepted_offer'
                ? ['kind' => 'offer', 'id' => $d->offer_id, 'reference' => $d->offer?->reference]
                : ['kind' => 'purchase_intent', 'intent_id' => $d->intent_id, 'confirmation_id' => $d->confirmation_id, 'confirmation_reference' => $d->confirmation?->reference],
            'listing' => ['id' => $d->listing_id, 'slug' => $d->listing->slug, 'reference' => $d->listing->reference, 'title' => $d->listing_title, 'currently_live' => $live],
            'shop' => ['id' => $d->shop_id, 'name' => $d->shop->name] + ($this->audience === 'buyer' ? ['slug' => $d->shop->slug] : []),
            'terms' => [
                'product_name' => $d->product_name, 'product_kind' => $d->product_kind, 'quantity' => Decimal::trim((string) $d->quantity), 'unit' => $d->unit_code,
                'unit_price' => $this->money($d->unit_price), 'product_total' => $this->money($d->product_total), 'currency' => $d->currency,
                'listed_unit_price' => $this->money($d->listed_unit_price), 'price_basis' => $d->terms_source === 'accepted_offer' ? 'negotiated' : 'listed',
                'listing_version' => $d->listing_version, 'total_covers' => 'product_only', 'frozen' => true,
            ],
            'fulfilment' => [
                'method' => $d->fulfilment_method, 'label' => $d->fulfilment_method === 'pickup' ? 'Pickup' : 'Seller-arranged delivery', 'listing_offered' => $d->listing_fulfilment,
                'pickup_area' => $d->pickup_area, 'delivery_coverage' => $d->delivery_coverage ?? [],
                'dispatch_estimate' => $d->dispatch_estimate ? ['value' => $d->dispatch_estimate, 'label' => MarketplaceProductCatalogue::DISPATCH[$d->dispatch_estimate] ?? $d->dispatch_estimate] : null,
                'delivery_charge' => self::deliveryCharge($d), 'arranged_by' => 'buyer_and_seller',
            ],
            'completion' => [
                'verification' => 'self_reported', 'state' => $this->completionState($d), 'buyer_confirmed_at' => $d->buyer_completed_at?->toIso8601String(),
                'seller_confirmed_at' => $d->seller_completed_at?->toIso8601String(), 'completed_at' => $d->completed_at?->toIso8601String(),
                'note' => 'Completion is reported by each party. Farmvest does not verify that goods or payment changed hands.',
            ],
            'cancellation' => $d->status === DealStatus::Cancelled
                ? ['cancelled_at' => $d->cancelled_at->toIso8601String(), 'by' => $d->cancelled_by_side, 'reason' => $d->cancel_reason, 'note' => $d->cancel_note] : null,
            'contact_available' => $d->status !== DealStatus::Cancelled, 'contact' => null,
            'created_at' => $d->created_at->toIso8601String(), 'notice' => self::NOTICE,
        ];

        if ($side !== null) {
            $out['you'] = $side;
            $out['can'] = [
                'complete' => $acts && $open && $own === null, 'cancel' => $acts && $open, 'report' => $acts, 'view_contact' => $acts && $d->status !== DealStatus::Cancelled,
            ];
        }
        if ($this->audience !== 'buyer') {
            $out['buyer'] = ['name' => $d->buyer->name] + ($this->audience === 'admin' ? ['id' => $d->buyer_id] : []);
        }
        if ($this->audience === 'admin' && isset($d->reports_count)) {
            $out['report_count'] = (int) $d->reports_count;
        }
        if ($this->detailed) {
            $out['history'] = $this->history($d);
            if ($this->audience === 'admin') {
                $out['reports'] = $d->relationLoaded('reports') ? $d->reports->map(fn ($r) => (new DealReportResource($r))->audience('admin')->resolve())->all() : [];
            } else {
                $out['my_reports'] = $d->reports->filter(fn ($r) => $r->reporter_side === $side)->map(fn ($r) => (new DealReportResource($r))->resolve())->values()->all();
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function deliveryCharge(MarketplaceDeal $d): array
    {
        $amount = $d->delivery_charge_amount === null ? null : bcadd((string) $d->delivery_charge_amount, '0', 2);

        return [
            'mode' => $d->delivery_charge_mode, 'amount' => $amount, 'currency' => $d->currency,
            'included_in_product_total' => false,
            'display' => match (true) {
                $d->delivery_charge_mode === 'not_applicable' => 'No delivery (pickup)',
                $d->delivery_charge_mode === 'included' => 'Delivery included in the unit price',
                $amount !== null => $d->currency.' '.self::formatMoney($amount).' agreed (not part of the product total)',
                default => 'To be agreed directly',
            },
        ];
    }

    /** "2500.50" -> "2,500.50", on the decimal string (no float is ever used for money). */
    public static function formatMoney(string $amount): string
    {
        [$int, $dec] = array_pad(explode('.', $amount, 2), 2, '00');

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $int).'.'.$dec;
    }

    private function completionState(MarketplaceDeal $d): string
    {
        return match (true) {
            $d->status === DealStatus::Completed => 'completed',
            $d->status === DealStatus::Cancelled => 'cancelled',
            $d->buyer_completed_at !== null => 'awaiting_seller',
            $d->seller_completed_at !== null => 'awaiting_buyer',
            default => 'awaiting_both',
        };
    }

    private function money(mixed $v): string
    {
        return bcadd((string) $v, '0', 2);
    }

    /**
     * The party-facing history. Reports are confidential: a party sees only that THEY reported, never that the other side did (admins see all).
     *
     * @return list<array<string, mixed>>
     */
    private function history(MarketplaceDeal $d): array
    {
        return $d->events
            ->filter(fn ($e) => $e->action !== 'reported' || $this->audience === 'admin' || $e->actor_kind === $this->audience)
            ->map(fn ($e) => ['action' => $e->action, 'actor' => $e->actor_kind, 'from' => $e->from_status, 'to' => $e->to_status, 'code' => $e->code, 'at' => $e->created_at->toIso8601String()])
            ->values()->all();
    }
}
