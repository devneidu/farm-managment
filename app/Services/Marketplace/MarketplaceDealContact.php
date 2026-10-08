<?php

namespace App\Services\Marketplace;

use App\Enums\DealStatus;
use App\Models\MarketplaceDeal;
use App\Models\MarketplaceDealContactView;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiHttpException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The only door through which a party's private contact leaves the system. Contact appears in no list, detail, public or enquiry payload; it is read here,
 * by the two parties of a deal (and platform admins), while the deal is `accepted` or `completed`. Each read is recorded (who, which side, which FIELD
 * NAMES - never values) in the append-only contact-access log and the audit trail.
 *
 * What is disclosed is the minimum the deal needs: the buyer receives the seller's shop contact (and the private address ONLY when the deal is a pickup);
 * the seller receives the buyer's display name and account email, plus the optional phone the buyer chose to give for this deal.
 */
class MarketplaceDealContact
{
    public const NOTICE = 'Arrange payment, transport and handover directly with the other party. Farmvest does not collect payment, hold funds, deliver goods or guarantee the outcome. Details already shared cannot be recalled.';

    public function __construct(private MarketplaceShopService $shops, private AuditLogger $audit) {}

    /** The seller's contact, for the buyer of the deal. @return array<string, mixed> */
    public function sellerContactFor(User $buyer, string $dealId): array
    {
        $deal = MarketplaceDeal::with('shop')->where('buyer_id', $buyer->id)->findOrFail($dealId);
        $this->assertReadable($deal);
        [$contact, $fields] = $this->sellerContact($deal);
        $this->record($deal, $buyer, 'buyer', $fields);

        return $this->envelope($deal, 'seller', $contact);
    }

    /** The buyer's contact, for a shop member who may respond to deals. @return array<string, mixed> */
    public function buyerContactFor(User $user, string $shopId, string $dealId): array
    {
        $shop = $this->shops->memberShop($user, $shopId);
        if (! $user->can('respondToDeals', $shop)) {
            throw new AuthorizationException;
        }
        $deal = MarketplaceDeal::with('buyer')->where('shop_id', $shop->id)->findOrFail($dealId);
        $this->assertReadable($deal);
        [$contact, $fields] = $this->buyerContact($deal);
        $this->record($deal, $user, 'seller', $fields);

        return $this->envelope($deal, 'buyer', $contact);
    }

    /** Both parties' contact, for a platform admin. Any deal state: investigating a complaint may need a cancelled deal. @return array<string, mixed> */
    public function forAdmin(User $admin, string $dealId): array
    {
        $deal = MarketplaceDeal::with('shop', 'buyer')->findOrFail($dealId);
        [$seller, $sellerFields] = $this->sellerContact($deal);
        [$buyer, $buyerFields] = $this->buyerContact($deal);
        $fields = array_merge(array_map(fn ($f) => "seller.$f", $sellerFields), array_map(fn ($f) => "buyer.$f", $buyerFields));
        MarketplaceDealContactView::create(['deal_id' => $deal->id, 'viewer_id' => $admin->id, 'viewer_kind' => 'admin', 'fields' => $fields]);
        $this->audit->platform($admin, 'platform.marketplace_deal_contact_viewed', 'marketplace_deal', $deal->id, $deal->reference, ['fields' => $fields]);

        return ['deal' => ['id' => $deal->id, 'reference' => $deal->reference, 'status' => $deal->status->value], 'seller' => $seller, 'buyer' => $buyer, 'notice' => self::NOTICE];
    }

    // ------------------------------------------------------------------ internals

    private function assertReadable(MarketplaceDeal $deal): void
    {
        if ($deal->status === DealStatus::Cancelled) {
            throw new ApiHttpException(409, 'deal_contact_unavailable', 'This deal was cancelled, so contact details are no longer available here. Details already shared cannot be recalled.', details: ['status' => $deal->status->value]);
        }
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function sellerContact(MarketplaceDeal $deal): array
    {
        $shop = $deal->shop;
        $channels = array_filter(['phone' => $shop->contact_phone, 'whatsapp' => $shop->contact_whatsapp, 'email' => $shop->contact_email], fn ($v) => $v !== null && $v !== '');
        $contact = ['name' => $shop->name, 'preferred_contact_method' => $shop->preferred_contact_method, 'channels' => $channels];
        $fields = array_map(fn ($k) => "channels.$k", array_keys($channels));
        if ($deal->fulfilment_method === 'pickup') {
            $contact['pickup'] = ['address_line' => $shop->address_line, 'area' => $deal->pickup_area];
            $fields[] = 'address_line';
        } else {
            $contact['delivery'] = ['coverage' => $deal->delivery_coverage ?? [], 'dispatch_estimate' => $deal->dispatch_estimate];   // no private address for a delivery deal
        }

        return [$contact, $fields];
    }

    /** @return array{0: array<string, mixed>, 1: list<string>} */
    private function buyerContact(MarketplaceDeal $deal): array
    {
        $channels = array_filter(['email' => $deal->buyer->email, 'phone' => $deal->buyer_contact_phone], fn ($v) => $v !== null && $v !== '');
        $contact = ['name' => $deal->buyer->name, 'preferred_contact_method' => isset($channels['phone']) ? 'phone' : 'email', 'channels' => $channels];

        return [$contact, array_map(fn ($k) => "channels.$k", array_keys($channels))];
    }

    /** @param  list<string>  $fields */
    private function record(MarketplaceDeal $deal, User $viewer, string $kind, array $fields): void
    {
        MarketplaceDealContactView::create(['deal_id' => $deal->id, 'viewer_id' => $viewer->id, 'viewer_kind' => $kind, 'fields' => $fields]);
        $deal->loadMissing('shop');
        $this->audit->record($deal->shop->farm_id, $viewer->id, 'marketplace.deal_contact_viewed', 'marketplace_deal', $deal->id, $deal->reference, ['viewer' => $kind, 'fields' => $fields]);
    }

    /** @param  array<string, mixed>  $contact */
    private function envelope(MarketplaceDeal $deal, string $party, array $contact): array
    {
        return [
            'deal' => ['id' => $deal->id, 'reference' => $deal->reference, 'status' => $deal->status->value, 'fulfilment_method' => $deal->fulfilment_method],
            'party' => $party, 'contact' => $contact, 'notice' => self::NOTICE,
        ];
    }
}
