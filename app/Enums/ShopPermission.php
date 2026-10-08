<?php

namespace App\Enums;

/** Shop-scoped permissions. Checked by MarketplaceShopPolicy, never by role name. Listing permissions arrived in Phase 23; negotiation/deal permissions are added here later. */
enum ShopPermission: string
{
    case View = 'shop.view';
    case Update = 'shop.update';
    case ManageContact = 'shop.manage_contact';
    case ManageLifecycle = 'shop.manage_lifecycle';
    case ManageMembers = 'shop.manage_members';
    /** Read listings (all roles). */
    case ListingView = 'listing.view';
    /** Create and edit DRAFT listings and their images (staff included). */
    case ListingManage = 'listing.manage';
    /** Publish, pause, archive, restore and edit LIVE listings, and delete drafts' history-bearing state (owner, manager). */
    case ListingPublish = 'listing.publish';
}
