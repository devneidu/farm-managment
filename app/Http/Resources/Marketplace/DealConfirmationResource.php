<?php

namespace App\Http\Resources\Marketplace;

use App\Enums\ConfirmationStatus;
use App\Models\MarketplaceDealConfirmation;
use App\Support\Measurement\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The seller's offer to proceed on exact terms for a fixed-price purchase request. NOT an agreement and NOT a deal: nothing exists between the parties, and
 * no contact is shared, until the buyer confirms. The status is the EFFECTIVE one (an unanswered confirmation past its deadline reads `lapsed`).
 *
 * @property MarketplaceDealConfirmation $resource
 */
class DealConfirmationResource extends JsonResource
{
    public static $wrap = null;

    private string $audience = 'buyer';

    public function audience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $status = $c->effectiveStatus();
        $amount = $c->delivery_charge_amount === null ? null : bcadd((string) $c->delivery_charge_amount, '0', 2);

        $out = [
            'id' => $c->id, 'reference' => $c->reference, 'kind' => 'deal_confirmation', 'status' => $status->value, 'agreement' => $status === ConfirmationStatus::Converted ? 'deal' : 'none',
            'intent_id' => $c->intent_id,
            'listing' => ['id' => $c->listing_id, 'slug' => $c->listing->slug, 'title' => $c->listing_title],
            'shop' => ['id' => $c->shop_id, 'name' => $c->shop->name] + ($this->audience === 'buyer' ? ['slug' => $c->shop->slug] : []),
            'terms' => [
                'product_name' => $c->product_name, 'quantity' => Decimal::trim((string) $c->quantity), 'unit' => $c->unit_code, 'unit_price' => bcadd((string) $c->unit_price, '0', 2),
                'product_total' => bcadd((string) $c->total_amount, '0', 2), 'currency' => $c->currency, 'total_covers' => 'product_only',
            ],
            'fulfilment' => [
                'method' => $c->fulfilment_method,
                'delivery_charge' => [
                    'amount' => $amount, 'included_in_product_total' => false,
                    'display' => $c->fulfilment_method === 'pickup' ? 'No delivery (pickup)' : ($amount !== null ? $c->currency.' '.DealResource::formatMoney($amount).' proposed (not part of the product total)' : 'To be agreed directly'),
                ],
            ],
            'expires_at' => $c->expires_at->toIso8601String(), 'created_at' => $c->created_at->toIso8601String(), 'contact' => null,
            'deal' => $c->deal ? ['id' => $c->deal->id, 'reference' => $c->deal->reference, 'status' => $c->deal->status->value] : null,
            'note' => $status === ConfirmationStatus::AwaitingBuyer
                ? 'The seller has offered to proceed on these terms. It is not an agreement until the buyer confirms, and no contact details are shared before then. Nothing is reserved.'
                : null,
        ];
        if ($this->audience === 'seller') {
            $out['buyer'] = ['name' => $c->buyer->name];
            $out['withdrawable'] = $status === ConfirmationStatus::AwaitingBuyer;
        } else {
            $out['confirmable'] = $status === ConfirmationStatus::AwaitingBuyer;
        }

        return $out;
    }
}
