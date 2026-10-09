# Phase 23 — Marketplace product listings, pricing & images (implementation record)

API contract: [`docs/api/PHASE-23-MARKETPLACE-LISTINGS.md`](../api/PHASE-23-MARKETPLACE-LISTINGS.md); image assets: [`PHASE-23-IMAGE-ASSET-RUNBOOK.md`](../api/PHASE-23-IMAGE-ASSET-RUNBOOK.md). Scope and the out-of-scope list (offers/negotiation Phase 24, deals Phase 25, monetisation Phase 26, community Phase 28, checkout/payments/escrow/logistics, stock reservation, Phase 20) are in the contract.

## Product decisions applied (review of the design)

1. **Staff** create and edit *draft* listings (and their photos) but cannot publish, pause, archive, restore or change a live listing (`listing.manage` vs `listing.publish`; owner/manager hold both).
2. **Images**: private local disk (`marketplace`), served by the application; storage goes through `Storage::disk(config('marketplace.images.disk'))` so an S3 disk is a configuration change.
3. **Selling units**: `basket`, `tuber`, `bunch` added through the insert-only measurement provisioning (still package units with no family/conversion; `tuber` whole-number). No universal package conversions.
4. **Publishing** is immediate for an active, approved shop; admins restrict afterwards.
5. **An image is never required**: an image-less listing publishes and returns a placeholder indicator.
6. **Inventory**: agreement is not payment or fulfilment. Nothing in Phase 23 creates a Sale or stock movement; the contract §6.1 records that a future accepted deal may only reach inventory through the explicit, confirmed Sales workflow with a deal-derived idempotency key.
7. **Package contents** are seller-declared, descriptive and never converted or treated as stock.
8. **Moderation** is `restrict` / `lift-restriction` only (no `hide`); nothing is deleted; history and audit are append-only.
9. Existing envelopes, pagination, validation and error conventions are reused; money and quantity maths use `Decimal` (bcmath) only.

## Design notes

* **Visibility is computed, not stored.** `MarketplaceListing::public()` = `status = published` AND the shop is `MarketplaceShop::public()` (active). Suspending/closing a shop therefore hides its listings in the same instant with no per-listing write; reinstating restores them untouched.
* **Concurrency.** Writes lock the shop row, then the listing row (same order everywhere; asserted by a test) and compare an optional `version` token (`409 stale_listing`). Transitions to a state already held succeed unchanged (no version bump/history/audit). Reference allocation uses a named `GET_LOCK` like shops; unique `reference`/`slug` indexes are the backstop. Quantities are stored at `decimal(24,6)` and normalised to that scale before comparison so a re-sent equal value is not a change.
* **Pricing.** `decimal(18,2)` NGN per one selling unit, parsed with `Decimal::parse` (strings, ints and exactly-printed floats), ≤2 decimals; quantity precision from `units.decimal_places` / `integer_only`; `min_order <= available`; totals rounded half away from zero to kobo; `amount_minor` is an exact integer from `bcmul`.
* **Master data reuse.** `species_id` / `crop_type_id` reference existing tables; `MarketplaceProductCatalogue` is the only place defining kinds → allowed units and exposes them via `GET /marketplace/product-options`. A custom name may refine a reference (a deliberate relaxation of "exactly one" so Catfish/Rice/Tomatoes, which master data does not list, work).
* **Images.** Catalogue = reusable rows referenced by id (never copied). Resolution: seller photo → chosen → name match → species/crop match → kind fallback → placeholder. Uploads: size/MIME/dimension/pixel guards before decode, GD decode + re-encode (drops EXIF/GPS and appended payloads, applies orientation, downscales to 2048 px), random storage name, per-listing sha256 de-duplication, max 6, served with `nosniff`. Remote URLs are never accepted. The catalogue is seeded without assets (no invented files/URLs).
* **Inventory link** is a reference + snapshot, requires `marketplace.manage` and `inventory.view` on the shop's farm, validates the item inside that farm, and compares quantities only when the units are identical.
* **Search** is `LIKE` (escaped) rather than full-text: InnoDB full-text indexes are not visible inside open transactions, which would make the feature untestable and surprising for fresh writes.

## Files

* Migrations `2026_10_20_100000_create_marketplace_listings` (`marketplace_catalog_images`, `marketplace_listings`, `marketplace_listing_images`, `marketplace_listing_events`) and `2026_10_20_100100_provision_marketplace_selling_units_and_image_catalogue` (insert-only data).
* Enums `ListingStatus`; `ShopPermission` (+3), `ShopRole` permission map; policy methods on `MarketplaceShopPolicy`.
* Models `MarketplaceListing` (`public()` scope), `MarketplaceListingImage`, `MarketplaceListingEvent` (append-only), `MarketplaceCatalogImage`; seeder `MarketplaceImageCatalogueSeeder`; `MeasurementSeeder` (+3 package units).
* Services `Marketplace/{MarketplaceProductCatalogue, MarketplaceListingRules, MarketplaceListingService, MarketplaceListingDirectory, MarketplaceListingModerationService, MarketplaceListingHistory, MarketplaceImageService, MarketplaceInventoryLink}`.
* Controllers `Marketplace/{MarketplaceCatalogueController, MarketplaceListingController, MarketplaceListingImageController, MarketplacePublicListingController}`, `Platform/PlatformMarketplaceListingController`; requests and resources under `Marketplace/`.
* `config/marketplace.php`, `marketplace` filesystem disk, limiter `marketplace-upload`.

## Not built (deliberate)

Offers/negotiation, deals, buyer checkout/payments/wallets/escrow, logistics, stock reservation or sync jobs, full-text/relevance engine, image CDN/S3 rollout, video, listing expiry/renewal, favourites/reports by buyers, per-plan listing limits, seller notifications on restriction.
