# Phase 23 — Marketplace product listings, pricing & images

Contract for **seller product listings** in the Marketplace. **Implemented:** listings with reusable agricultural master data, flexible selling units, decimal-safe NGN pricing, a negotiable flag (data only), seller-arranged fulfilment, an illustrative image library plus seller photos, an optional informational link to the shop farm's inventory, a listing lifecycle, the anonymous public feed and platform-admin restriction. **Not implemented (later phases):** buyer offers and negotiation (Phase 24), deal agreements (Phase 25), marketplace monetisation (Phase 26), community (Phase 28), buyer checkout, payments, wallets, escrow, payment commissions, Farmvest logistics, delivery bookings or guarantees, stock reservation. Phase 20 integrations are untouched.

All paths are under `/api/v1`. Success envelope `{data, meta, message}`; errors `{message, code, request_id, errors?, details?}` (`API-CONTRACT.md` §4). The generated spec is `docs/api/openapi.json`; Postman: folder **24 — Marketplace listings** and **90 → Marketplace Listings**. Shop foundations (roles, lifecycle, contact privacy) are in `PHASE-22-MARKETPLACE.md`.

## 1. Concepts and rules

| Topic | Rule |
|---|---|
| Ownership | Every listing belongs to **one marketplace shop**. It is reached only through the caller's shop membership: a shop or listing the caller cannot see answers `404` (existence is never revealed), a member lacking the permission gets `403`. A **marketplace-only seller needs no farm**. |
| Roles | New shop permissions (§2): `listing.view` (all members), `listing.manage` (create/edit **drafts** and their photos: owner, manager, **staff**), `listing.publish` (publish, pause, archive, restore, edit non-drafts: **owner and manager only**). Staff can prepare drafts but can never publish, archive, restore or change a live listing. Platform admins restrict/lift (§8). Farm roles confer nothing here. |
| Publishing | An **active, approved shop** publishes **immediately**; no per-listing admin approval. Platform admins can restrict afterwards (post-moderation). A listing is public only while `status = published` **and** its shop is `active`: the check runs on every read, so suspending or closing a shop removes its listings from discovery **at once**, with no per-listing write; reinstating restores them. |
| Images optional | An image is **never required** to publish. An image-less listing returns `image.source = "placeholder"`, `image.has_image = false`, `image.url = null` and `image.placeholder_kind` so the frontend renders its own placeholder. |
| Money | NGN only (`currency: "NGN"`). Prices are **per one selling unit**. Money and quantities are **decimal strings** end to end (bcmath); no float is used for any authoritative value. Price: positive, at most 2 decimals, up to 10 integer digits. Responses carry `amount` (`"8000.00"`) and exact `amount_minor` (kobo, integer). |
| Quantities | Unit-aware: **whole numbers** for indivisible units (`head`, `egg`, `piece`, `tuber`), otherwise at most the unit's `decimal_places` (kg 3, l 2, package units 2). Always `> 0`. `available_quantity` is **declared by the seller**; the public API labels it `basis: "seller_declared"` and never exposes live stock. `min_order_quantity` is optional, `> 0` and `<= available_quantity` (re-checked whenever either changes). |
| Packages | A **container** unit (`bag`, `sack`, `crate`, `tray`, `carton`, `bottle`, `basket`) **requires** `package {quantity, unit, description?}`: what one container holds, **as declared by the seller** (`unit` is a content unit: `kg, g, tonne, l, ml, piece, egg, head`). Package statements are **descriptive only**: never a conversion, never used to compute stock, never inferred (`is_conversion: false`, `declared_by: "seller"`). Non-container units reject `package`. Changing the selling unit drops the old statement; it must be restated. |
| Product identity | `product_kind` + existing master data: livestock/fish use `species_id` (the species list is split by operation category: fish = aquaculture), crops use `crop_type_id`, eggs/milk may name a producing `species_id`; `custom_product_name` covers what master data does not (required for `other`; for livestock/fish/crops either a master-data reference **or** a name is required, both are allowed so a name can refine the reference: `fish` + "Catfish"). There is **no second taxonomy**. |
| Negotiable | `negotiable: true|false`, seller-controlled. It is **data only** in Phase 23: no offer or negotiation endpoint exists. `true` makes the listing eligible for Phase 24. |
| Fulfilment | Seller-arranged only: `pickup`, `seller_delivery`, `both`. Public: a **general** `pickup_area`, `delivery_coverage[]` (states/cities, ≤20), `dispatch_estimate`, `delivery_charge` (`included`/`agreed_separately`). The exact address and contact values stay in the private shop contact (Phase 22). Details that do not apply to the chosen mode are dropped. No transport, booking, SLA or insurance fields exist. |
| Inventory link | Optional, **informational** (§6). Linking, publishing, pausing, editing or viewing a listing **never** creates a movement, reserves or deducts stock, or creates a Sale. |
| Concurrency | Every write locks the **shop row, then the listing row** inside one transaction and compares the optional `version` token (`409 stale_listing`). Transitions are idempotent (§3). References/slugs are allocated under a named lock; unique indexes are the final guard. |

### 1.1 Product kinds and selling units

`GET /marketplace/product-options` is the authority; the table is a snapshot.

| `product_kind` | Allowed selling units | Product reference |
|---|---|---|
| `livestock` | `head` | `species_id` (non-aquatic species) or name |
| `fish` | `kg`, `head` | `species_id` (aquaculture) and/or name (e.g. Catfish) |
| `crop_produce` | `kg, tonne, piece, bag, sack, basket, crate, tuber, bunch` | `crop_type_id` or name (Rice, Tomatoes) |
| `eggs` | `egg, tray, crate, carton` | optional producing `species_id` |
| `milk` | `l, bottle` | optional producing `species_id` |
| `feed` | `kg, tonne, bag, sack` | name |
| `other` | `kg, g, l, piece, bag, sack, crate, tray, carton, bottle, basket, bunch, tuber` | `custom_product_name` required |

`basket`, `tuber` and `bunch` are new **system package units** (insert-only data migration; no family, so they convert to nothing). `tuber` is whole-number only. No universal conversion exists for any package unit.

### 1.2 Examples

| Listing | `product_kind` | `unit` | `unit_price` | quantity |
|---|---|---|---|---|
| Chicken, N8,000 per head | `livestock` (+ species chicken) | `head` | `"8000"` | `"120"` |
| Catfish, N3,500 per kg | `fish` (+ species fish, name "Catfish") | `kg` | `"3500"` | `"250.5"` |
| Eggs, N6,000 per crate | `eggs` | `crate` + `package {30 egg}` | `"6000"` | `"40"` |
| Yam, N2,000 per tuber | `crop_produce` (+ crop yam) | `tuber` | `"2000"` | `"300"` |
| Tomatoes, N15,000 per basket | `crop_produce` (name "Tomatoes") | `basket` + `package {25 kg}` | `"15000"` | `"20"` |

Totals use exact decimals, rounded half away from zero to kobo: `3500.55 x 2.333 kg = 8166.78` (`total_minor 816678`); `0.10 x 3 = 0.30`.

## 2. Roles and permissions (shop-scoped)

| Role | Listing permissions |
|---|---|
| `owner` | `listing.view`, `listing.manage`, `listing.publish` |
| `manager` | `listing.view`, `listing.manage`, `listing.publish` |
| `staff` | `listing.view`, `listing.manage` (drafts only) |

`GET /marketplace/my/shops` and `ShopResource.viewer.permissions` list them. `ListingResource.abilities` tells the UI whether `edit` and `publish` are available for that listing and viewer.

| Action | Permission | Notes |
|---|---|---|
| list/show/price-preview | `listing.view` | |
| create, edit a **draft**, add/edit/remove photos of a draft, delete a draft, link inventory | `listing.manage` | linking also needs the farm permissions (§6) |
| publish, pause, archive, restore, edit a **published/paused** listing and its photos | `listing.publish` | owner, manager |

Edits to `archived` listings are refused (`409 invalid_listing_state`, restore first); `restricted` listings are frozen (`409 listing_restricted`); a suspended shop is frozen (`409 shop_suspended`, reads still work).

## 3. Lifecycle

```
draft ──publish──▶ published ◀──publish── paused
  ▲  ▲                 │  └──pause─────────▲
  │  └─restore─ archived ◀──archive── (draft | published | paused)
admin: draft|published|paused ──restrict──▶ restricted ──lift──▶ paused      (the seller then publishes again)
delete: draft only (soft delete; history and audit rows stay)
```

* `publish`: from `draft` or `paused`. Needs the shop `active` (`409 shop_not_active`, `details.shop_status`) and a complete listing (`422 listing_incomplete`, `details.missing` ⊂ `state, pickup_area, delivery_coverage, delivery_charge`). Sets `published_at` on first publication only.
* **Idempotent**: repeating `publish`, `pause`, `archive` or `restore` when the listing is already in the target state returns `200` with the unchanged listing — no version bump, no history row, no audit row.
* `version` (optional on every field write) guards the listing's fields and lifecycle; photo uploads/edits/deletes are a separate sub-resource and do **not** change it (so "upload photos, then Save" never trips `stale_listing`). When present and different from the current one → `409 stale_listing` with `details.current_version`; nothing is changed. Send the version you loaded.
* Every transition writes an **append-only history row** (`marketplace_listing_events`: action, from, to, actor kind/id, reason) and an audit entry.

Public visibility (`is_public`) = `status = published` AND shop `active`. For a published listing whose shop is not active the seller sees `is_public: false`, `hidden_because: "shop_not_active"`.

## 4. Resources

### 4.1 Seller listing (`ListingResource`)

```json
{
  "id": "019f1000-0000-7000-8000-000000000001", "reference": "LST-2026-00001", "slug": "fresh-eggs-by-the-crate-k3x9ab", "shop_id": "019f0000-…", "version": 3,
  "status": "published", "is_public": true, "hidden_because": null,
  "title": "Fresh eggs by the crate", "description": null,
  "product": {"kind": "eggs", "kind_label": "Eggs", "species": null, "crop_type": null, "custom_name": null, "name": "Eggs"},
  "price": {"amount": "6000.00", "amount_minor": 600000, "currency": "NGN", "per": {"code": "crate", "name": "Crate", "symbol": "crate", "integer_only": false, "decimal_places": 2}},
  "quantity": {"available": "40", "min_order": null, "unit": "crate", "basis": "seller_declared", "updated_at": "2026-10-20T09:00:00+00:00"},
  "negotiable": false,
  "package": {"quantity": "30", "unit": "egg", "description": "Standard crate of 30 eggs", "declared_by": "seller", "is_conversion": false},
  "fulfilment": {"mode": "pickup", "label": "Pickup only", "pickup_area": "Bodija market", "delivery_coverage": [], "dispatch_estimate": null, "delivery_charge": null, "arranged_by": "seller"},
  "location": {"state": "Oyo", "city": "Ibadan", "area": "Bodija"},
  "image": {"source": "placeholder", "kind": null, "illustrative": true, "has_image": false, "url": null, "alt_text": "Fresh eggs by the crate", "placeholder_kind": "eggs"},
  "catalog_image_id": null, "images": [], "inventory_linked": false,
  "restriction": null, "abilities": {"edit": true, "publish": true},
  "published_at": "2026-10-20T09:05:00+00:00", "paused_at": null, "archived_at": null, "created_at": "…", "updated_at": "…"
}
```

`GET …/listings/{listing}` additionally returns `history[]` (`action, from, to, actor_kind, reason, at`) and `inventory` (§6). `restriction` is `{reason, at}` while `restricted`.

### 4.2 Image object (`image`)

| `source` | Meaning | `illustrative` | `url` |
|---|---|---|---|
| `seller` | the seller's primary photo (position 0) | `false` | API file route |
| `catalogue` | the catalogue image the seller chose, or one matching the product name, species or crop | `true` | catalogue file route |
| `fallback` | the category-level catalogue fallback for the `product_kind` | `true` | catalogue file route |
| `placeholder` | nothing available | `true` | `null` (`placeholder_kind` = `product_kind`) |

Resolution order: seller photo → chosen catalogue image → catalogue image matching `custom_product_name` (label) → matching species/crop → kind fallback → placeholder. **Catalogue images are illustrations, never actual product photos**; show a caption when `illustrative` is `true`. Seller photos are listed in `images[]` (`id, url, alt_text, position, width, height, kind: "seller", illustrative: false`).

### 4.3 Public listing (`PublicListingResource`) — explicit allow-list

`id, reference, slug, title, description, product, price, quantity, negotiable, package, fulfilment, location, image, shop (PublicShopResource), published_at`, plus `images[]` on the detail view only. **Never present:** `farm_id`, any inventory field or live stock, creator/member identity, `version`, `status`, moderation data, contact values, street address.

### 4.4 Platform listing (`PlatformListingResource`)

`id, reference, slug, status, version, is_public, hidden_because, title, product, price, quantity, negotiable, shop {id, reference, slug, name, status, verification_status}, restriction {reason, at, by}, published_at, created_at, updated_at, deleted_at`; the detail view adds `description, package, fulfilment, location, image, images[], farm_id, inventory_linked, history[]`.

## 5. Seller endpoints

Middleware: signed in, active account, verified email (**no farm, no onboarding**). Writes: `throttle:marketplace-write` (60/min per user); uploads also `throttle:marketplace-upload` (20/min). `{shop}`, `{listing}`, `{image}` are UUIDs.

### 5.1 `GET /marketplace/product-options`
Everything the form needs in one call: `kinds[] {code, label, units[] {code, name, symbol, integer_only, decimal_places, requires_package_details}, products[] {type, id, code, name}, allows_custom_name, custom_name_required, inventory_linkable}`, `fulfilment[]`, `dispatch_estimates[]`, `delivery_charges[]`, `package_content_units[]`, `currency`, `limits {max_images_per_listing, max_image_kilobytes, image_mime_types, max_delivery_coverage_entries}`. `401`.

### 5.2 `GET /marketplace/image-library`
Reusable **illustrative** catalogue entries that have an asset: `id, code, label, product_kind, kind_fallback, illustrative: true, alt_text, url`. Query `product_kind`. Empty until assets are seeded (§9). `401`, `422`.

### 5.3 `GET /marketplace/shops/{shop}/listings`
`listing.view`. Query: `status`, `product_kind`, `q` (title, reference, product name), `page`, `per_page` ≤50. Newest first. `200 {data: ListingResource[], meta}`.

### 5.4 `POST /marketplace/shops/{shop}/listings`
`listing.manage`. Creates a private `draft` (`201`).

| Field | Rules |
|---|---|
| `title` | required, 3-150 |
| `product_kind` | required, one of the kinds in §1.1 |
| `species_id` / `crop_type_id` | optional UUID of an **active** master-data row that fits the kind; `422` otherwise |
| `custom_product_name` | optional ≤120; required for `other`; for livestock/fish/crops a reference or a name is required |
| `unit` | required code; must be allowed for the kind (`422 unit`) |
| `unit_price` | required decimal, > 0, ≤2 decimals (string preferred; JSON numbers are read exactly) |
| `available_quantity` | required decimal > 0, honouring the unit (whole/decimal places) |
| `min_order_quantity` | optional decimal > 0, ≤ `available_quantity`, same unit rules |
| `negotiable` | optional bool (default `false`) |
| `package` | `{quantity, unit, description?}` — required for container units, rejected otherwise |
| `fulfilment` | optional `pickup` (default) \| `seller_delivery` \| `both` |
| `pickup_area` | optional ≤120 general area |
| `delivery_coverage` | optional array ≤20 of strings ≤80 |
| `dispatch_estimate` | optional `same_day, 1_2_days, 3_5_days, within_week, to_be_agreed` |
| `delivery_charge` | optional `included` \| `agreed_separately` |
| `state`, `city`, `area` | optional; default to the shop's location |
| `description` | optional ≤3000 |
| `catalog_image_id` | optional UUID from the image library (`422` if unknown/unavailable). **Never a URL.** |
| `inventory_item_id` | optional UUID of an item of the shop farm's inventory, see §6 |

Ignored if sent: `status, version, shop_id, created_by, farm_id, slug, reference, currency, published_at, …`.
Errors: `401`, `403`, `404`, `409 shop_suspended`, `422` (keyed by field: `unit`, `unit_price`, `available_quantity`, `min_order_quantity`, `package`, `package.unit`, `package.quantity`, `species_id`, `crop_type_id`, `custom_product_name`, `fulfilment`, `catalog_image_id`, `inventory_item_id`). Audit `marketplace.listing_created`.

### 5.5 `GET …/listings/{listing}`
`listing.view`. Detail with `history` and (for linked listings, authorised members only) `inventory`.

### 5.6 `PATCH …/listings/{listing}`
Same fields, all optional, plus `version`. Rules: draft → `listing.manage`; published/paused → `listing.publish`. A live listing **stays live** while edited. Validation runs on the effective state, so changing `unit` re-validates price/quantities and requires a restated `package`. `inventory_item_id: null` unlinks. Errors: `403`, `404`, `409 stale_listing`, `409 invalid_listing_state` (archived), `409 listing_restricted`, `409 shop_suspended`, `422`. A request that changes nothing does not bump `version`. Audit `marketplace.listing_updated` (`changes.fields`; old/new `unit_price` and `available_quantity` when they change).

### 5.7 `DELETE …/listings/{listing}`
`listing.manage`. Soft-deletes a **draft** (`409 invalid_listing_state` otherwise — archive a listing that has been public). Slugs and references are never reused; history and audit rows stay. Audit `marketplace.listing_deleted`.

### 5.8 Lifecycle: `POST …/publish|pause|archive|restore`
`listing.publish`. Optional body `{version}`. See §3. Errors: `403`, `404`, `409 shop_not_active`, `409 invalid_listing_state`, `409 listing_restricted`, `409 stale_listing`, `409 shop_suspended`, `422 listing_incomplete`. Audit `marketplace.listing_published|paused|archived|restored`.

### 5.9 `POST …/price-preview` and `GET /public/marketplace/listings/{slug}/price-preview`
Body/query `{quantity}`. A **non-binding** estimate: `{quantity, unit, unit_price, currency, total, total_minor, binding: false, note}`. `422 quantity` when it breaks the unit rules, is below the minimum order or above the declared available quantity.

### 5.10 `GET …/listings/eligible-inventory`
See §6.

### 5.11 Photos
Same rights as editing the listing (draft → `listing.manage`, otherwise `listing.publish`). Up to **6** per listing.

* `POST …/listings/{listing}/images` — `multipart/form-data`: `image` (JPEG, PNG or WebP, ≤5 MB, ≤4096 px longest side, ≤16 megapixels) and optional `alt_text` (≤160). The file's real type is checked, the image is **decoded and re-encoded** (EXIF/GPS and any appended payload are dropped; EXIF orientation is applied), downscaled to ≤2048 px, stored on the private `marketplace` disk as `listings/{shop}/{listing}/{random-uuid}.{ext}` and served only by the API. `201` (or `200` with the existing photo when the same picture is already on the listing). Remote URLs are **never accepted or fetched**. Errors: `409 image_limit_reached` (`details.limit`), `422 image`, plus the edit errors of §5.6. Audit `marketplace.listing_image_added`.
* `PATCH …/images/{image}` `{alt_text?, position?}` — `position` 0 is the primary photo; others are renumbered.
* `DELETE …/images/{image}` — removes the row and the stored file; positions are renumbered.
* `GET …/images/{image}/file` — member-only stream of the stored file (drafts included).
* A photo addressed through another listing or another shop's member answers `404`.

## 6. Inventory linking (optional, informational)

For shops created with `farm_id` only (a marketplace-only shop has no inventory: `422 inventory_item_id`). To link, the caller must also hold, **on the shop's farm**, `marketplace.manage` and `inventory.view` (`403` otherwise).

* `GET /marketplace/shops/{shop}/listings/eligible-inventory?product_kind=` → `[{id, name, category, kind, on_hand {quantity, unit}}]`: the farm's **active, sellable** items (categories `produce`, `feed`) that can back that kind — eggs → the automatic Eggs item, milk → Milk, feed → feed items, crop_produce/other → produce items that are not eggs/milk. Medicine, seed, agrochemicals and general supplies are never offered. Livestock and fish are populations, not stock (`422`).
* `inventory_item_id` on create/update: the item must belong to the shop's own farm. A foreign, unknown, inactive or ineligible item all answer the same `422 inventory_item_id` (the id is never trusted).
* The listing stores a **snapshot** (`inventory_synced_quantity`, `inventory_synced_at`). The seller console's `inventory` block shows `on_hand` (live) next to the declared quantity, `changed_since_link`, `unit_comparable` and `exceeds_stock` — compared **only when the listing unit and the stock unit are the same**; nothing is converted or assumed (`egg` vs `piece` are not comparable).
* **What it never does:** reserve, deduct, post or sync stock; change `available_quantity`; create a Sale. The public API shows only the seller-declared quantity and never an inventory id, a farm id or live stock, so a quantity is never presented as guaranteed availability. `quantity.updated_at` tells buyers how fresh the declaration is.

### 6.1 Future deals (Phases 24-25) — design note

*Agreement is not payment or fulfilment.* Accepting an offer in Phase 24/25 must **not** create a farm Sale or deduct stock by itself. A farm Sale / stock-out may only be created by an **explicitly authorised, confirmed sales workflow** (the existing `POST /sales` with a stock line, performed by a member with the sales permission after fulfilment/payment is confirmed) carrying an **idempotency key derived from the deal** (for example the deal id), so a retry or a double click can never double-post. The deal references `listing.id` for provenance only; the listing's declared quantity and package statement are never treated as authoritative stock conversions. Linked inventory stays the single source of truth for stock and changes only through its own movements.

## 7. Public endpoints (no authentication)

`throttle:60,1` (images `240/min`). Only `published` listings of `active` shops; everything else is `404`/absent.

### 7.1 `GET /public/marketplace/listings`
Filters: `q` (title, description, product name, species and crop names; `%`/`_` are escaped), `product_kind`, `species` and `crop_type` (master-data **codes**), `state`, `city`, `min_price`, `max_price` (naira per unit, ≤2 decimals, `max >= min`; **prices of different units are not comparable — combine with `unit`**), `unit`, `negotiable`, `fulfilment` (`pickup|seller_delivery`: listings that *offer* it, so a `both` listing matches either), `delivers_to` (state/city in the seller's coverage), `shop` (slug), `verified`. `sort`: `newest` (default; ties broken newest-first by id), `price_asc`, `price_desc`, `relevance` (title matches first, then product-name matches; reads as newest without `q`). `page`, `per_page` ≤50 (default 20). `200 {data: PublicListingResource[], meta: {current_page, per_page, last_page, total}}`; `422` for invalid filters.

### 7.2 `GET /public/marketplace/listings/{slug}`
Detail with all photos. `404` for anything not currently public.

### 7.3 Files
* `GET /public/marketplace/images/{image}` — a seller photo, only while its listing is public.
* `GET /public/marketplace/catalogue-images/{code}` — an illustrative catalogue image, `404` while it has no asset or is inactive.
* Headers: `Content-Type` from the stored (re-encoded) type, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`, `Cache-Control: public, max-age=3600`.

## 8. Platform-admin endpoints (`/platform-admin/marketplace/listings`)

Reads: any platform role. Writes: role `admin` (`403` for `support`); `throttle:platform-admin-write`. **Restrict and Lift only — there is no separate "hide"; restricting is hiding. Nothing is deleted.** Each decision runs under a row lock in one transaction with its history row and audit entry.

| Method & path | Body | Effect / errors | Audit |
|---|---|---|---|
| `GET /` | query `q`, `status`, `shop_id`, `product_kind`, `page`, `per_page` ≤100 | all listings (not soft-deleted), newest first | — |
| `GET /{listing}` | — | detail incl. photos and full `history` (deleted drafts too) | — |
| `POST /{listing}/restrict` | `reason` (3-500, required) | `draft|published|paused → restricted`: removed from the feed at once and frozen against seller edits/publishing; the seller sees the reason. Repeating is a no-op. `409 invalid_listing_state` (archived) | `platform.marketplace_listing_restricted` |
| `POST /{listing}/lift-restriction` | `reason` (required, 3-500; Phase 27) | `restricted → paused`; **never republishes by itself** (the seller publishes). `409 invalid_listing_state` otherwise | `platform.marketplace_listing_restriction_lifted` |
| `GET /{listing}/images/{image}/file` | — | stream a photo for review | — |

## 9. Image catalogue and assets

Tables: `marketplace_catalog_images` (reusable illustrative rows, mapped to master data by code and to a `product_kind`; one `is_kind_fallback` row per kind), `marketplace_listing_images` (seller photos). A listing stores `catalog_image_id`; the image is **never copied per listing**. The migration seeds the **structure only** (chicken, goat, cattle, sheep, pig, rabbit, fish, catfish, yam, cassava, maize, vegetables, fruits, rice, tomatoes, eggs, milk + 7 fallbacks) with `asset_path = NULL`: no image files or URLs were invented. Rows awaiting an asset are invisible (library, listings, file route) until a licensed asset is provided — follow `PHASE-23-IMAGE-ASSET-RUNBOOK.md`. Storage is the `marketplace` disk (`config/marketplace.php`, `MARKETPLACE_DISK`); pointing it at an S3 disk changes nothing else.

## 10. Errors

| Status | `code` | When |
|---|---|---|
| 401 / 403 | — / `email_verification_required` | not signed in / unverified email; `403` without the shop or farm permission |
| 404 | `not_found` | non-member, other shop's listing/photo, non-public listing |
| 409 | `shop_suspended`, `shop_not_active`, `invalid_listing_state`, `listing_restricted`, `stale_listing`, `image_limit_reached` | see §3, §5 |
| 422 | `listing_incomplete` (`details.missing`) and field errors | see §5 |

Audit actions: seller `marketplace.listing_created|updated|deleted|published|paused|archived|restored|image_added|image_updated|image_removed` (carry the shop's `farm_id` or none; field names and prices only, never contact values); admin `platform.marketplace_listing_restricted|restriction_lifted` (before/after status and `reason`).

## 11. Known limits

* Search is a `LIKE` match (not full-text) so results are consistent inside transactions; adequate for V1 volumes.
* Prices in different units are not comparable; the feed does not normalise them.
* Seller photos are served through the application (no CDN/S3 yet); `Cache-Control` is short.
* The EXIF-stripping re-encode changes JPEG bytes (quality 85) — originals are not retained.
