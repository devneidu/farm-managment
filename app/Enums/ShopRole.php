<?php

namespace App\Enums;

/** A user's role inside one shop. `owner` is created with the shop and is never assignable. */
enum ShopRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Staff = 'staff';

    /** @return list<ShopPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ShopPermission::cases(),
            self::Manager => [ShopPermission::View, ShopPermission::Update, ShopPermission::ManageContact, ShopPermission::ListingView, ShopPermission::ListingManage, ShopPermission::ListingPublish, ShopPermission::OfferView, ShopPermission::OfferRespond],
            self::Staff => [ShopPermission::View, ShopPermission::ListingView, ShopPermission::ListingManage, ShopPermission::OfferView],
        };
    }

    public function can(ShopPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** @return list<self> roles an owner may grant */
    public static function assignable(): array
    {
        return [self::Manager, self::Staff];
    }
}
