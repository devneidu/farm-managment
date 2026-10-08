<?php

namespace App\Enums;

/** Shop-scoped permissions. Checked by MarketplaceShopPolicy, never by role name. Listing permissions arrived in Phase 23; offer permissions arrived in Phase 24; deal permissions arrived in Phase 25; billing permissions arrived in Phase 26. */
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
    /** Read buyer offers and purchase intents (all roles). */
    case OfferView = 'offer.view';
    /** Accept or reject a buyer offer (owner, manager). */
    case OfferRespond = 'offer.respond';
    /** Read deals (all roles). Contact details are NOT included in this permission. */
    case DealView = 'deal.view';
    /** Confirm purchase intents, complete or cancel a deal, report, and read the buyer's contact for a deal (owner, manager). */
    case DealRespond = 'deal.respond';
    /** Read the shop's Farmvest service payments and promotions (owner, manager). */
    case BillingView = 'billing.view';
    /** Buy a seller plan or a promotion for the shop and re-check a payment (owner, manager). */
    case BillingManage = 'billing.manage';
}
