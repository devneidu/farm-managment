<?php

namespace App\Enums;

/** Shop-scoped permissions. Checked by MarketplaceShopPolicy, never by role name. Future listing/negotiation/deal permissions are added here. */
enum ShopPermission: string
{
    case View = 'shop.view';
    case Update = 'shop.update';
    case ManageContact = 'shop.manage_contact';
    case ManageLifecycle = 'shop.manage_lifecycle';
    case ManageMembers = 'shop.manage_members';
}
