<?php

namespace App\Policies;

use App\Enums\ShopPermission;
use App\Models\MarketplaceShop;
use App\Models\MarketplaceShopMember;
use App\Models\User;

/**
 * Shop authorization. The caller must be a member of the shop and their shop role must grant the permission; farm roles confer nothing here.
 * MarketplaceShopService::memberShop() loads the `viewer` relation (and 404s non-members) so the policy normally costs no extra query.
 */
class MarketplaceShopPolicy
{
    public function view(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::View);
    }

    public function update(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::Update);
    }

    public function manageContact(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::ManageContact);
    }

    public function manageLifecycle(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::ManageLifecycle);
    }

    public function manageMembers(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::ManageMembers);
    }

    public function viewListings(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::ListingView);
    }

    /** Create / edit DRAFT listings and their images. */
    public function manageListings(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::ListingManage);
    }

    /** Publication authority: publish, pause, archive, restore, edit live listings. */
    public function publishListings(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::ListingPublish);
    }

    public function viewOffers(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::OfferView);
    }

    /** Accept or reject a buyer offer. */
    public function respondToOffers(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::OfferRespond);
    }

    public function viewDeals(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::DealView);
    }

    /** Confirm purchase intents, complete, cancel, report, and read the buyer's contact. */
    public function respondToDeals(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::DealRespond);
    }

    public function viewBilling(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::BillingView);
    }

    /** Buy a Farmvest service for the shop (seller plan, promotion) and re-check one of its payments. */
    public function manageBilling(User $user, MarketplaceShop $shop): bool
    {
        return $this->can($user, $shop, ShopPermission::BillingManage);
    }

    private function can(User $user, MarketplaceShop $shop, ShopPermission $permission): bool
    {
        $member = $shop->relationLoaded('viewer') ? $shop->getRelation('viewer') : $shop->members()->where('user_id', $user->id)->first();

        return $member instanceof MarketplaceShopMember && $member->user_id === $user->id && $member->can($permission);
    }
}
