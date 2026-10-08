<?php

namespace App\Http\Resources\Marketplace;

use App\Models\MarketplaceServicePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Farmvest service payment (a seller plan or a promotion - never a buyer-seller payment). `benefit_granted` is true only once the provider confirmed the
 * payment server-side and the benefit was activated. The checkout link is shown only while the payment is still pending. Provider internals (access code,
 * gateway status) are for administrators.
 *
 * @property MarketplaceServicePayment $resource
 */
class ServicePaymentResource extends JsonResource
{
    public static $wrap = null;

    private string $audience = 'seller';

    public function audience(string $audience): static
    {
        $this->audience = $audience;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $out = [
            'id' => $p->id, 'reference' => $p->reference, 'purpose' => $p->purpose, 'subject' => $p->subject_label, 'amount' => (string) $p->amount, 'currency' => $p->currency,
            'status' => $p->status, 'benefit_granted' => $p->settled_at !== null,
            // Paid but nothing activated (for example the shop was suspended meanwhile): Farmvest support follows it up; it is never lost.
            'needs_attention' => $p->settled_at === null && $p->settlement_issue !== null,
            'authorization_url' => $p->status === MarketplaceServicePayment::PENDING ? $p->authorization_url : null,
            'plan_id' => $p->plan_id, 'interval_days' => $p->interval_days, 'package_id' => $p->package_id, 'listing_id' => $p->listing_id,
            'paid_at' => $p->paid_at?->toIso8601String(), 'settled_at' => $p->settled_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
        ];
        if ($this->audience === 'admin') {
            $out += [
                'shop' => $p->relationLoaded('shop') && $p->shop ? ['id' => $p->shop->id, 'name' => $p->shop->name] : ['id' => $p->shop_id], 'payer_id' => $p->user_id, 'provider' => $p->provider,
                'gateway_status' => $p->gateway_status, 'failure_reason' => $p->failure_reason, 'settlement_issue' => $p->settlement_issue, 'verified_at' => $p->verified_at?->toIso8601String(),
            ];
        }

        return $out;
    }
}
