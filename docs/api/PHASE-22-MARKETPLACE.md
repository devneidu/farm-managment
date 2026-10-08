# Phase 22 — Marketplace foundation & seller shops

Contract for the seller-shop foundation of the Marketplace. **Implemented:** shop onboarding, profile, private contact configuration, publishing lifecycle, verification badge, shop members/roles, anonymous discovery, platform-admin oversight. **Not implemented in Phase 22 (listings arrived in [Phase 23](PHASE-23-MARKETPLACE-LISTINGS.md)):** product listings, buyer negotiation, deals/orders, buyer payments/wallets/escrow/payouts, delivery, marketplace subscriptions, community. Nothing here touches Phase 20.

All paths are under `/api/v1`. Success envelope `{data, meta, message}`; errors `{message, code, request_id, errors?, details?}` (see `API-CONTRACT.md` §4). The generated spec is `docs/api/openapi.json` (203 paths); Postman: folder **23 — Marketplace** and **90 → Marketplace Shops**.

## 1. Concepts

| Concept | Rule |
|---|---|
| Who may sell | Any signed-in account with a **verified email**. **No farm and no farm onboarding is needed** (`onboarded_at` is never touched; creating a shop never creates a farm). A seller without a farm gets `next_action = "marketplace"` from the auth state as soon as they belong to a shop (see §1.3); a brand-new account with no shop still gets `complete_farm_setup`, and the frontend offers "Open a marketplace shop instead" (`POST /marketplace/shops`). Drive the seller area from `GET /marketplace/my/shops`. |
| Farm link | Optional `farm_id` at creation only. The caller must be an **active member of that farm with the farm permission `marketplace.manage`** (Owner, Manager). A farm has at most one shop. The link is immutable. The shop stores no farm business data and no response of the seller or public API ever contains `farm_id` (only `farm_backed: bool`; platform admins see `farm_id`). |
| Ownership | A shop belongs to **users** through shop members with a shop role, independent of farm roles. The creator is `owner`. |
| Publishing | A shop is public only while `status = active`. New shops are `draft`. Only a platform admin approval makes a shop `active`. |
| Verification | A separate trust badge (`verification.status`), independent of publishing. |
| Private contact | Phone, WhatsApp, email, street address and preferred method are **private**: readable only by members with `shop.manage_contact` through `GET /marketplace/shops/{shop}/contact` (and by platform admins on the admin detail view). Public and profile responses list only the **names** of configured channels (`contact_methods`). |

### 1.1 Shop roles and permissions

| Role | Permissions |
|---|---|
| `owner` | `shop.view`, `shop.update`, `shop.manage_contact`, `shop.manage_lifecycle`, `shop.manage_members` |
| `manager` | `shop.view`, `shop.update`, `shop.manage_contact` |
| `staff` | `shop.view` (Phase 23 adds `listing.view`, `listing.manage`; managers/owners also get `listing.publish` - see PHASE-23-MARKETPLACE-LISTINGS.md §2) |

A non-member always gets `404` for a shop (existence is not revealed); a member lacking the permission gets `403`. Farm roles confer nothing on a shop. The platform setting `marketplace_max_shops_per_user` (integer 1-20, default **3**, set through `PUT /platform-admin/settings/marketplace_max_shops_per_user`) limits how many shops one user may own.

### 1.2 Lifecycle (`status`)

```
draft ──submit──▶ pending_review ──approve──▶ active ◀──reopen── closed
  ▲                    │   │                    │  └──close──────▲
  │                    │   └─reject─▶ rejected  │
  └──────submit────────┴──────────────(fix, resubmit)
any submitted state ──suspend──▶ suspended ──reinstate──▶ active (if ever approved) | pending_review
```

* `submit`: `draft|rejected → pending_review`; profile must be complete (§3.7).
* `approve`/`reject`: admin only, from `pending_review`. `reject` stores a `reason` shown to the seller.
* `suspend`: admin only, from `pending_review|active|closed|rejected` (a `draft` cannot be suspended: it is already private). Hidden from the public immediately; the seller can read the shop and `status_reason` but every seller write answers `409 shop_suspended`, and the seller cannot reopen it.
* `reinstate`: `suspended → active` if `approved_at` is set, otherwise `pending_review`. A suspension never grants an approval.
* `close`/`reopen`: seller (owner), `active ↔ closed`. Only an approved shop can be closed, so reopening needs no new review.
* An `active` shop may be edited and stays public (post-moderation; admins can suspend).

Verification (`verification.status`): `unverified → pending` (seller `request-verification`, shop must be `active`) `→ verified | rejected` (admin). Admin may also `verified` an unverified/rejected shop directly, and revoke `verified → unverified`. The badge requires an approved shop and never changes `status`.

### 1.3 Auth state for marketplace sellers (additive)

Every auth endpoint (`register`, `login`, `google`, `email/verify`, `onboarding/farm`, `invitations/accept`, `GET /auth/me`) returns the same state object. Phase 22 adds, without changing any existing field:

| Field | Meaning |
|---|---|
| `data.marketplace.shop_count` | shops the user is a member of (any status); `0` for everyone without a shop |
| `data.next_action = "marketplace"` | new value, returned **only** when the user is verified, has **no active farm** and `shop_count > 0`. Route to the seller dashboard (`GET /marketplace/my/shops`). It takes priority over `complete_farm_setup` and `no_active_farm` for that user |

Everything else is unchanged: a user with an active farm keeps `next_action = "none"` whether or not they also run shops; a user with neither a farm nor a shop keeps `complete_farm_setup` (or `no_active_farm` after losing their last farm); `verify_email` is always first. Farm endpoints still answer `403 onboarding_required` / `no_active_farm` to a marketplace-only user, `onboarded` stays `false` until they choose to create a farm, and `POST /onboarding/farm` still works for them at any time (the account, its shops and sessions are the same; `next_action` becomes `none` and both areas are available). No endpoint, permission or onboarding step was added.

## 2. Resources

### 2.1 Seller shop (`ShopResource`)

```json
{
  "id": "019f0000-0000-7000-8000-000000000001", "reference": "SHP-2026-00001", "slug": "ada-poultry-hub",
  "name": "Ada Poultry Hub", "tagline": "Fresh eggs daily", "description": "Layers, broilers and fresh crates of eggs delivered across Ibadan.",
  "seller_type": "business", "categories": ["livestock", "eggs_dairy"],
  "location": {"country_code": "NG", "state": "Oyo", "city": "Ibadan", "area": "Bodija"},
  "farm_backed": false, "contact_methods": ["in_app", "phone"], "preferred_contact_method": "phone",
  "status": "draft", "status_reason": null, "is_public": false, "submitted_at": null, "approved_at": null,
  "verification": {"status": "unverified", "reason": null, "requested_at": null, "verified_at": null},
  "viewer": {"role": "owner", "permissions": ["shop.view", "shop.update", "shop.manage_contact", "shop.manage_lifecycle", "shop.manage_members"]},
  "created_at": "2026-10-19T09:00:00+00:00", "updated_at": "2026-10-19T09:00:00+00:00"
}
```

`contact_methods` always starts with `in_app` and adds `phone|whatsapp|email` for each configured channel (names only). `status_reason` is the admin's reason when `rejected` or `suspended`.

### 2.2 Private contact (`ShopContactResource`)

```json
{"address_line": "12 Market Road, Bodija", "contact_phone": "+2348031234567", "contact_whatsapp": "+2348099998888", "contact_email": "ada.private@example.com", "preferred_contact_method": "whatsapp"}
```

### 2.3 Public shop (`PublicShopResource`) — explicit allow-list

```json
{
  "id": "019f0000-0000-7000-8000-000000000001", "slug": "ada-poultry-hub", "name": "Ada Poultry Hub", "tagline": "Fresh eggs daily",
  "description": "Layers, broilers and fresh crates of eggs delivered across Ibadan.", "seller_type": "business", "categories": ["livestock", "eggs_dairy"],
  "location": {"country_code": "NG", "state": "Oyo", "city": "Ibadan", "area": "Bodija"},
  "verified": true, "verified_at": "2026-10-20T08:00:00+00:00", "farm_backed": false,
  "contact_methods": ["in_app", "phone", "whatsapp", "email"], "member_since": "2026-10-19"
}
```

Never present: owner/member identity, farm id, contact values, street address, lifecycle reasons, audit data. `id` is the future foreign key for listings; `slug` is the stable public address.

### 2.4 Shop member (`ShopMemberResource`)

```json
{"id": "019f...", "role": "manager", "user": {"id": "019f...", "name": "Bola", "email": "bola@example.com"}, "created_at": "2026-10-19T09:00:00+00:00"}
```

### 2.5 Platform shop (`PlatformShopResource`)

The seller shop (without `viewer`'s meaning: `viewer` is `null`) plus `owner {id, name, email}`, `farm_id`, `suspended_at`, `closed_at`. The **list** omits private data; the **detail and action responses** add `contact` (the §2.2 object) so support can reach the seller during review.

## 3. Seller endpoints

Middleware: signed in, active account, verified email. Writes: `throttle:marketplace-write` (60/min per user). `{shop}` and `{member}` are UUIDs.

### 3.1 `GET /marketplace/my/shops`
Every shop the caller belongs to, newest first (§2.1, with `viewer`). `200 {data: ShopResource[]}`.

### 3.2 `POST /marketplace/shops`
Creates a `draft` shop; the caller becomes `owner`; `slug` and `reference` are generated.

| Field | Rules |
|---|---|
| `name` | required, 2-120, unique among the caller's own shops |
| `seller_type` | required: `individual`, `business`, `farm` |
| `tagline` | optional, ≤160 |
| `description` | optional, ≤2000 |
| `categories` | optional array ≤7 distinct of `livestock, crops, eggs_dairy, feed_inputs, equipment, processed_goods, services` |
| `country_code` | optional ISO alpha-2 (default `NG`) |
| `state`, `city`, `area` | optional, ≤60 / 80 / 120 (`area` is public: neighbourhood/landmark) |
| `farm_id` | optional UUID, see §1 |

Unknown/forbidden inputs (`status`, `slug`, `verification_status`, `created_by`, …) are ignored. `201 {data: ShopResource, message: "Shop created."}`.
Errors: `401`; `403 email_verification_required`; `403` (farm member without `marketplace.manage`); `409 shop_limit_reached` (`details.limit`); `409 farm_shop_exists`; `422` (validation; `name` already used; `farm_id` not a farm you belong to — a foreign farm and a missing farm answer identically). Audit `marketplace.shop_created`.

### 3.3 `GET /marketplace/shops/{shop}`
Any member. `404` for non-members. `200 {data: ShopResource}`.

### 3.4 `PATCH /marketplace/shops/{shop}` — profile
Needs `shop.update`. Same fields as §3.2 except `farm_id`, all optional; `slug` never changes. `409 shop_suspended`. Audit `marketplace.shop_updated` (`changes.fields` = names only; no entry when nothing changed).

### 3.5 `GET /marketplace/shops/{shop}/contact`, `PATCH …/contact`
Needs `shop.manage_contact`. Body (all optional, `null` clears): `address_line` ≤255, `contact_phone` and `contact_whatsapp` (`^\+?[0-9]{7,15}$`), `contact_email` (email), `preferred_contact_method` (`in_app|phone|whatsapp|email`; phone/whatsapp/email require that channel to be configured, else `422`). `409 shop_suspended`. Audit `marketplace.shop_contact_updated` (field names only, never values).

### 3.6 `POST …/close`, `POST …/reopen`, `POST …/request-verification`
Need `shop.manage_lifecycle` (owner). Errors: `409 invalid_shop_state` (`details.status`), `409 shop_suspended`, `409 verification_not_requestable`. Audit `marketplace.shop_closed|shop_reopened|verification_requested`.

### 3.7 `POST …/submit`
Needs `shop.manage_lifecycle`. Requires: description ≥20 characters, `state`, `city`, ≥1 category, ≥1 private contact channel. Otherwise `422 shop_incomplete` with `details.missing` (subset of `description,state,city,categories,contact`). `409 invalid_shop_state`, `409 shop_suspended`. Audit `marketplace.shop_submitted`. The shop is **still not public**.

### 3.8 Members (owner only: `shop.manage_members`)

* `GET /marketplace/shops/{shop}/members` → `ShopMemberResource[]` (owner first).
* `POST …/members` `{email, role: manager|staff}` → `201`. The account must exist, have a verified email and not be suspended; **every other case answers the same `422` on `email`** (existence is not revealed). `409 already_member`. Audit `marketplace.member_added`.
* `PATCH …/members/{member}` `{role: manager|staff}`; `409 owner_protected`. Audit `marketplace.member_role_changed`.
* `DELETE …/members/{member}`; `409 owner_protected`; access ends immediately. Audit `marketplace.member_removed`.
* All: `409 shop_suspended` while suspended.

Seller-side audit entries belong to the shop's farm when it is farm-backed (visible in that farm's audit trail to `audit.view` holders) and to no farm otherwise (visible to platform admins in `GET /platform-admin/audit-logs`).

## 4. Public endpoints (no authentication, `throttle:60,1`)

### 4.1 `GET /public/marketplace/shops`
Only `active` shops. Query: `q` (name/tagline/description), `state`, `city` (exact), `category`, `seller_type`, `verified` (`1|0`), `sort` (`newest` = by approval date, default; `name`), `page`, `per_page` (≤50, default 20). `200 {data: PublicShopResource[], meta: {current_page, per_page, last_page, total}}`. `422` for invalid filters.

### 4.2 `GET /public/marketplace/shops/{slug}`
By `slug` (not id). `404 not_found` for any non-active shop — draft, pending, rejected, suspended and closed shops are indistinguishable from missing ones.

## 5. Platform-admin endpoints (`/platform-admin/marketplace/shops`)

Reads: any platform role. Writes: role `admin` (`403 platform_write_forbidden` for `support`; `403 platform_admin_required` for everyone else, farm Owners included); `throttle:platform-admin-write`. Every decision runs under a row lock in one transaction with its audit entry, so a second admin gets `409`.

| Method & path | Body | Effect / errors | Audit action |
|---|---|---|---|
| `GET /` | query `q` (name/slug/reference), `status`, `verification_status`, `farm_backed`, `page`, `per_page`≤100 | list; `status=pending_review` = review queue, oldest submission first | — |
| `GET /{shop}` | — | detail incl. `contact` | — |
| `POST /{shop}/approve` | — | `pending_review → active`, sets `approved_at`; `409 invalid_shop_state` | `platform.marketplace_shop_approved` |
| `POST /{shop}/reject` | `reason` (3-500) | `pending_review → rejected` | `platform.marketplace_shop_rejected` |
| `POST /{shop}/suspend` | `reason` (3-500) | see §1.2; `409 invalid_shop_state` | `platform.marketplace_shop_suspended` |
| `POST /{shop}/reinstate` | — | `suspended → active|pending_review` | `platform.marketplace_shop_reinstated` |
| `POST /{shop}/verification` | `decision` (`verified|rejected|unverified`), `reason` (required for the last two) | `409 invalid_verification_state`, `409 shop_not_approved` | `platform.marketplace_verification_{decision}` |

Audit entries carry `changes.before/after` (`status` or `verification_status`) and `changes.reason`, with `farm_id = null`.

## 6. Farm permission

`marketplace.manage` (new, Owner and Manager presets; appears in `GET /roles` and `GET /farm → membership.permissions`) is the only farm-side right: it allows creating a shop linked to that farm. All other shop actions use shop roles.

## 7. Foundation for later phases

* Listings, negotiations and deals will reference `marketplace_shops.id` and call `MarketplaceShop::public()` / `ShopPermission` for visibility and authorization; add new `ShopPermission` cases and grant them in `ShopRole::permissions()`.
* The `active` + `approved_at` gate is the single publishing check; listings must additionally require an `active` shop.
* Monetisation (subscriptions, commissions) is intentionally absent; the shop owner and `verification` are the hooks.
