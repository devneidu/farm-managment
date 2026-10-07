# Phase 22 — Marketplace foundation & seller shops (implementation record)

API contract: [`docs/api/PHASE-22-MARKETPLACE.md`](../api/PHASE-22-MARKETPLACE.md). Scope and out-of-scope list are in that document.

## Decisions

1. **A shop belongs to users, not farms.** Marketplace-only sellers have no farm, so shop ownership is `marketplace_shop_members(shop_id, user_id, role)` with a shop-scoped role enum (`ShopRole` → `ShopPermission`) checked by `MarketplaceShopPolicy`. `FarmRole`/`Permission` are not reused for shop actions.
2. **`farm_id` is an optional creation-time link**, unique per farm, never trusted from the client: it is accepted only for an *active* membership of the caller with the new farm permission `marketplace.manage`. A non-member and a non-existent farm answer identically. The link is never exposed to sellers or the public.
3. **Two independent axes**: `status` (draft, pending_review, rejected, active, suspended, closed) controls publishing; `verification_status` (unverified, pending, verified, rejected) is a trust badge. Publishing requires platform approval; a suspension can never create an approval (`approved_at` is kept on the row).
4. **Private contact lives in separate columns and a separate resource.** The public resource is an explicit allow-list; `contact_methods` lists channel names only. Audit stores field names, never contact values.
5. **No onboarding change for farm users; marketplace-only sellers get their own route.** Marketplace routes need `auth:sanctum`, `account.active`, `email.verified` only (not `app.access`/`farm.context`); `onboarded_at` is never touched. Final-review correction: the auth state gained `marketplace.shop_count` and one new `next_action` value, `marketplace`, returned only for a verified user with no active farm who belongs to at least one shop (so it cannot affect any pre-Phase-22 user). Brand-new users with no shop keep `complete_farm_setup` and the frontend offers "open a shop instead". No new endpoint, no skip flag, no second account; `POST /onboarding/farm` still works for a marketplace-only user.
6. **Reuse**: platform settings registry (`marketplace_max_shops_per_user`), `PlatformAudit`/`AuditLogger`, `Paginates`, platform-admin middleware, `ApiHttpException` codes. No new infrastructure packages.
7. **Concurrency**: shop mutations use `lockForUpdate` on the shop row; creation serialises reference/slug allocation and the per-user limit with a MySQL named lock (`GET_LOCK`) released after commit. Unique indexes (`reference`, `slug`, `farm_id`) are the final guard.

## Files

* Migration `2026_10_19_100000_create_marketplace_shops` (additive; `marketplace_shops`, `marketplace_shop_members`).
* Enums `ShopStatus`, `ShopVerificationStatus`, `ShopRole`, `ShopPermission`; farm `Permission::MarketplaceManage` (Owner via `cases()`, Manager explicit).
* Models `MarketplaceShop` (`public()` scope = single visibility definition), `MarketplaceShopMember`; policy `MarketplaceShopPolicy`.
* Services `Marketplace/{MarketplaceShopService, MarketplaceModerationService, MarketplaceDirectory}`.
* Controllers `Marketplace/{MarketplaceShopController, MarketplaceShopMemberController, MarketplacePublicController}`, `Platform/PlatformMarketplaceController`; requests and resources under `Marketplace/`.
* Routes in `routes/api/v1.php`; limiter `marketplace-write`; platform setting key added to `PlatformConfigService::SETTINGS`.

## Not built (deliberate)

Ownership transfer, a member leaving a shop on their own, shop invitations by email to non-users, seller notifications on approve/reject/suspend, shop images/logo, coordinates/maps, deleting a shop, per-plan shop limits (the platform setting applies to everyone), a feature flag to switch the marketplace off.
