<?php

namespace App\Services\Marketplace;

use App\Enums\InventoryCategory;
use App\Enums\MembershipStatus;
use App\Enums\Permission;
use App\Models\FarmMembership;
use App\Models\InventoryItem;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockReasonCatalogue;
use App\Support\Measurement\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The OPTIONAL link between a listing and the shop farm's own inventory. It is a reference plus a snapshot, nothing more:
 *  - it never reduces, reserves or posts stock (no movement is ever written here);
 *  - the listing quantity stays seller-declared, and the public API never shows live stock;
 *  - the link only exists inside the shop's own farm, for a user who holds that farm's `marketplace.manage` and `inventory.view`.
 * Future accepted deals reach inventory only through the confirmed Sales workflow (docs/api/PHASE-23-MARKETPLACE-LISTINGS.md#future-deals).
 */
class MarketplaceInventoryLink
{
    public function __construct(private StockLedger $ledger) {}

    /** The caller must be an active member of the SHOP'S farm with both permissions; otherwise 403 (shop without a farm: 422). */
    public function authorizeFarm(User $user, MarketplaceShop $shop): FarmMembership
    {
        if ($shop->farm_id === null) {
            throw ValidationException::withMessages(['inventory_item_id' => 'This shop is not linked to a farm, so it has no inventory to link.']);
        }
        $membership = FarmMembership::where('farm_id', $shop->farm_id)->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)->first();
        if ($membership === null || ! $membership->can(Permission::MarketplaceManage) || ! $membership->can(Permission::InventoryView)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    /**
     * Resolves the item to link (inside the shop's farm only) and takes the snapshot. A foreign, unknown or ineligible item answers identically.
     *
     * @return array{farm_id: string, inventory_item_id: string, inventory_synced_quantity: string, inventory_synced_at: Carbon}
     */
    public function link(User $user, MarketplaceShop $shop, string $itemId, string $productKind): array
    {
        $this->authorizeFarm($user, $shop);
        $item = InventoryItem::where('farm_id', $shop->farm_id)->with('stockUnit.dimension')->find($itemId);
        if ($item === null || ! $this->eligible($item, $productKind)) {
            throw ValidationException::withMessages(['inventory_item_id' => 'This inventory item cannot be linked to the listing.']);
        }

        return [
            'farm_id' => $shop->farm_id, 'inventory_item_id' => $item->id,
            'inventory_synced_quantity' => $this->onHand($item)['quantity'], 'inventory_synced_at' => now(),
        ];
    }

    /** @return array<string, null> */
    public function unlinked(): array
    {
        return ['farm_id' => null, 'inventory_item_id' => null, 'inventory_synced_quantity' => null, 'inventory_synced_at' => null];
    }

    /** Sellable categories only (produce, feed), active, and matching the listing's product kind. Livestock and fish are populations, not stock. */
    public function eligible(InventoryItem $item, string $productKind): bool
    {
        if (! $item->is_active || ! $item->category->isSellable() || ! in_array($productKind, MarketplaceProductCatalogue::INVENTORY_KINDS, true)) {
            return false;
        }
        $outputKind = $item->system_key !== null ? StockReasonCatalogue::kindOf($item) : null;

        return match ($productKind) {
            MarketplaceProductCatalogue::EGGS => $outputKind === StockReasonCatalogue::KIND_EGGS,
            MarketplaceProductCatalogue::MILK => $outputKind === StockReasonCatalogue::KIND_MILK,
            MarketplaceProductCatalogue::FEED => $item->category === InventoryCategory::Feed,
            default => $outputKind === null && $item->category === InventoryCategory::Produce,   // harvested produce that is not the automatic eggs / milk stock
        };
    }

    /**
     * Items the caller may link to a listing of this kind, with live on-hand shown to that authorised seller only.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function eligibleItems(User $user, MarketplaceShop $shop, ?string $productKind): Collection
    {
        $this->authorizeFarm($user, $shop);

        return InventoryItem::where('farm_id', $shop->farm_id)->where('is_active', true)->whereIn('category', array_map(fn ($c) => $c->value, InventoryCategory::sellable()))
            ->with('stockUnit.dimension')->orderBy('name')->orderBy('id')->get()
            ->filter(fn (InventoryItem $i) => $productKind === null ? true : $this->eligible($i, $productKind))
            ->map(fn (InventoryItem $i) => ['id' => $i->id, 'name' => $i->name, 'category' => $i->category->value, 'kind' => StockReasonCatalogue::kindOf($i), 'on_hand' => $this->onHand($i)])
            ->values();
    }

    /**
     * The seller console's view of a linked listing: live on-hand next to what the listing declares, with a staleness signal. The listing quantity is
     * compared with stock only when both are in the SAME unit - nothing is converted or assumed.
     *
     * @return array<string, mixed>|null
     */
    public function view(MarketplaceListing $listing): ?array
    {
        if ($listing->inventory_item_id === null) {
            return null;
        }
        $item = InventoryItem::with('stockUnit.dimension')->find($listing->inventory_item_id);
        if ($item === null) {
            return null;
        }
        $onHand = $this->onHand($item);
        $comparable = $listing->relationLoaded('unit') ? $listing->unit->code === $onHand['unit'] : null;
        $synced = $listing->inventory_synced_quantity === null ? null : Decimal::trim((string) $listing->inventory_synced_quantity);

        return [
            'inventory_item_id' => $item->id, 'name' => $item->name, 'on_hand' => $onHand,
            'synced' => ['quantity' => $synced, 'at' => $listing->inventory_synced_at?->toIso8601String()],
            'changed_since_link' => $synced === null || Decimal::cmp($synced, $onHand['quantity']) !== 0,
            'unit_comparable' => $comparable,
            'exceeds_stock' => $comparable ? Decimal::cmp(Decimal::trim((string) $listing->available_quantity), $onHand['quantity']) > 0 : null,
            'note' => 'Informational. The listing quantity is declared by the seller; publishing never reserves or deducts stock.',
        ];
    }

    /** @return array{quantity: string, unit: string} */
    private function onHand(InventoryItem $item): array
    {
        return $this->ledger->display($item, $this->ledger->itemBalance($item->id));
    }
}
