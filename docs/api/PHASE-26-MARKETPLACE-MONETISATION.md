# Phase 26 — Marketplace monetisation (seller plans & promoted listings)

Authoritative contract for the frontend. Design record: [`../implementations/45-PHASE-26-MARKETPLACE-MONETISATION.md`](../implementations/45-PHASE-26-MARKETPLACE-MONETISATION.md). Builds on [Phase 23 listings](PHASE-23-MARKETPLACE-LISTINGS.md). Machine-readable: `openapi.json`; runnable: Postman folder **27 — Marketplace monetisation**.

**What this is:** two optional ways for Farmvest to earn from sellers, both paid **to Farmvest** through Paystack:

1. **Seller plans** — a shop is on the *Free* plan (default **10 published listings**) or has bought a prepaid **30-day or 365-day** period of a paid plan (*Seller Plus*, *Seller Pro*) with a larger limit.
2. **Promoted listings** — a fixed-price package (e.g. "7 days") that gives **one listing** priority placement on the first page of discovery and a **"Sponsored"** label.

**What this is not.** No commission, escrow, wallet, seller payout, refund or buyer–seller product payment — those stay entirely outside Farmvest. No stock is touched. No auto-renewal, no saved cards, no bidding, no targeting, no promotion credits bundled into plans. Nothing is charged until an administrator has configured a price **and** switched the feature on.

Seller routes: sign-in + verified email, **no farm** (`X-Farm-Id` not needed), inside a shop the caller belongs to (`404` otherwise). Checkout and verify use the `marketplace-checkout` throttle (20/min).

## 1. Switches and configuration (platform side)

| What | Where | Default |
|---|---|---|
| Sell paid plans | feature flag `marketplace_seller_plans` (`PATCH /platform-admin/feature-flags/{key}`) | **off** |
| Sell promotions | feature flag `marketplace_promotions` | **off** |
| Free listing limit | the **Free** plan's `listing_limit` (`PATCH /platform-admin/marketplace/seller-plans/{free-id}`) — works while the flags are off | **10** |
| Promoted listings leading page 1 | setting `marketplace_max_promoted_per_page` (`PUT /platform-admin/settings/{key}`; 0 = none, 10 max) | **3** |
| Paystack | server env `PAYSTACK_SECRET_KEY`, `MARKETPLACE_PAYMENT_CALLBACK_URL` | unset → checkout `503 payments_unavailable` |

Seeded: plans `free` (limit 10), `seller_plus`, `seller_pro` — the two paid plans are **inactive with no price and no limit**; Farmvest does not invent prices. A paid plan can be switched on only after it has a `listing_limit` and an active price (`422` otherwise). Turning a flag off stops *new* purchases only: a period or promotion already paid for keeps working until it ends.

## 2. Seller endpoints (`/api/v1/marketplace/shops/{shop}/…`)

| Method & path | Permission | Purpose |
|---|---|---|
| `GET /plan` | `listing.view` (all roles) | Current plan + allowance + `upcoming_periods` + `features` flags |
| `GET /allowance` | `listing.view` | Usage numbers behind the publish limit |
| `GET /plans` | `listing.view` | Active plans with 30/365-day prices; `meta.features` |
| `GET /promotion-packages` | `listing.view` | Active packages; `meta.features` |
| `POST /subscription/checkout` | `billing.manage` (owner, manager) | Body `{plan_id, interval_days: 30\|365}` → pending payment + `authorization_url` |
| `POST /listings/{listing}/promotions/checkout` | `billing.manage` | Body `{package_id}` → pending payment + `authorization_url` |
| `POST /payments/{reference}/verify` | `billing.manage` | Re-check a payment with Paystack (call when the buyer returns from checkout) |
| `GET /payments` | `billing.view` (owner, manager) | Payment history (`status`, `purpose`, `page`, `per_page`) |
| `GET /promotions` | `billing.view` | Promotion history (`state=running\|ended`) |

`billing.view` / `billing.manage` are new shop permissions: **owner and manager** have both; **staff have neither** (`403`), but staff can read plan and allowance so they can see why a publish was refused.

### 2.1 Allowance (`GET /allowance`, also embedded in `GET /plan`)

```json
{ "data": { "plan": {"code": "free", "name": "Free", "source": "free"},
  "listing_limit": 10, "unlimited": false, "published_count": 10, "remaining": 0,
  "over_limit_by": 0, "can_publish": false, "period": null } }
```

* `source` is `free` or `subscription`; `period` is `{starts_at, ends_at}` while a paid period runs.
* **Only `published` listings count.** Draft, paused, archived and restricted listings do not.
* **Existing listings are never deleted or paused** by an expiry, a downgrade or a lowered limit. `over_limit_by > 0` simply means the shop is above its allowance; it **cannot publish another listing until its published count is below the limit** (pause or archive some, or buy a plan).
* Plan changes are read when requested — an expired subscription reads as Free **at the moment it ends**, no scheduled job involved.
* A paid period keeps the limit it was bought with; later edits to the plan affect future purchases only.

### 2.2 Publishing at the limit

`POST …/listings/{id}/publish` (and publishing from paused) answers

```json
409 { "code": "listing_limit_reached", "message": "Your plan allows 10 published listings at a time. …",
      "details": { "listing_limit": 10, "published_count": 10, "over_limit_by": 0 } }
```

Re-publishing an already-published listing is still an idempotent success. The check runs under the shop lock, so simultaneous publishes cannot exceed the limit.

### 2.3 Checkout and payment (the safe flow)

```
1  POST …/subscription/checkout   →  201 { reference: "MSP-2026-00001", status: "pending", authorization_url, benefit_granted: false }
2  redirect the browser to authorization_url (Paystack hosted page)
3  Paystack redirects back to MARKETPLACE_PAYMENT_CALLBACK_URL?reference=MSP-…   ← proves NOTHING
4  frontend: POST …/payments/{reference}/verify   →  status paid + benefit_granted true   (only if Paystack confirms)
   (in parallel the signed webhook does the same; whichever comes first wins, the other is a no-op)
```

* The **price is frozen** when the checkout starts (from the current configuration). Admin price changes never alter a pending payment.
* Repeating a checkout for the same shop, user and item within 30 minutes returns the **same pending payment** (`200` instead of `201`) — double-clicks never create two charges.
* **Activation happens only on the server, only after Paystack itself confirms** `status = success`, the **exact amount in kobo**, the **currency (NGN)** and the **reference**. The redirect, the query string, the request body and the webhook body are never believed.
* `status`: `pending` → `paid` (or `failed`, or `abandoned` after 24 h unpaid). `benefit_granted` is true only once the plan/promotion is active. A `failed` payment whose `status` later becomes success at Paystack (customer retried on the same checkout) is honoured on the next verify.
* `needs_attention: true` = confirmed paid but nothing could be applied (the shop was suspended meanwhile, the listing is restricted/archived, or the listing was promoted by a racing payment). The money is on record and visible to platform admins; **nothing is silently converted or lost**. There is no automatic refund (see §6).
* `authorization_url` is returned only while `status = pending`. The Paystack secret key never appears in any response.

Checkout errors: `409 monetisation_disabled`, `409 plan_not_purchasable`, `409 subscription_active`, `409 package_not_available`, `409 listing_not_promotable`, `409 promotion_active`, `409 shop_not_active`, `403` (staff), `404` (another shop's listing), `422` (validation), `503 payments_unavailable` (Paystack not configured), `502 gateway_unavailable` (Paystack unreachable — nothing was charged; retry). `verify`: `404` for a reference that is not this shop's; `502 gateway_unavailable` if Paystack cannot be reached — **nothing is lost**, the payment is retried automatically (§5).

### 2.4 Subscriptions

* Prepaid; **no auto-renewal and no saved card**. `interval_days` is `30` or `365`.
* **Renewing the same plan extends the current paid period**: the new period starts exactly when the current one ends (`upcoming_periods` lists it). Periods of a shop never overlap.
* Buying a *different* paid plan while one is running is refused (`409 subscription_active`, with the running plan and its `ends_at`); it can be bought after the current period ends. No proration in V1.
* The subscription belongs to the **shop**, not the user or a farm. A seller without a farm is fully supported.

### 2.5 Promotions

* Needs a **published** listing of the caller's shop and an active package. **One live promotion per listing** (`409 promotion_active` with `expires_at`; the database enforces it too).
* The window runs from the moment payment is confirmed for the package's `duration_days`. `state` (history): `running | scheduled | expired | cancelled`, derived from the dates at read time.
* `benefit_active` is true only while the window is open **and** the listing is `published` **and** the shop is `active`. A suspended shop or a restricted/paused listing gets **no priority and is not compensated**: the window is neither extended nor refunded.

## 3. Discovery changes (public)

`GET /public/marketplace/listings` and `…/{slug}` gain `promotion`: `{"label": "Sponsored"}` while a listing is inside a paid window, otherwise `null`. **Always show the label when present.**

Ranking, on the default (`newest`) and `relevance` sorts only: up to `marketplace_max_promoted_per_page` (default **3**) promoted listings that match the buyer's filters lead **page 1**. Everything else — page 1's remainder and every later page — is the normal organic order, and **organic listings are never hidden or dropped** (every listing still appears exactly once across pages; `meta.total` is unchanged). Which promoted listings take the leading places rotates daily so equal payers are treated alike; promoted listings that do not lead appear in organic position, still labelled. `price_asc` / `price_desc` are never reordered by payment. Expired, cancelled, suspended-shop and restricted-listing promotions rank like nothing.

## 4. Platform admin (`/api/v1/platform-admin/marketplace/…`)

Reads: any platform role. Writes: role `admin`, `platform-admin-write` throttle, **audited**.

| Method & path | Purpose |
|---|---|
| `GET /seller-plans` | All plans with prices (incl. inactive) |
| `POST /seller-plans` | Create an **inactive** paid plan `{code, name, description?, listing_limit, sort_order?}` |
| `PATCH /seller-plans/{plan}` | `name, description, listing_limit, is_active, sort_order`. Free plan: its limit is *the* free allowance; it cannot be switched off (`409 free_plan_required`). Switching a paid plan on needs a limit and an active price (`422`) |
| `PUT /seller-plans/{plan}/prices` | `{prices:[{interval_days:30\|365, amount:"5000.00"\|null, is_active?}]}` (`null` removes). `409 free_plan_has_no_price` |
| `GET\|POST /promotion-packages`, `PATCH /promotion-packages/{package}` | Fixed price + duration (`code, name, description?, duration_days 1–365, amount, is_active, sort_order`); switched off, never deleted |
| `GET /service-payments` | Filters `status, purpose, shop_id, needs_attention, q`; includes `gateway_status`, `failure_reason`, `settlement_issue`, `verified_at`, shop, payer id |
| `GET /promotions` | Filters `state, shop_id` |
| `POST /promotions/{promotion}/cancel` | `{reason}` — stops it now, frees the listing, no-op if repeated. **No refund is made here** |

Money is **naira, decimal string, up to 2 decimals** (`"5000.00"`); Paystack is told the amount in kobo. All amounts are `NGN`.

Audit actions: `platform.marketplace_seller_plan_created|updated|prices_set`, `platform.marketplace_promotion_package_created|updated`, `platform.marketplace_promotion_cancelled`, `platform.setting_updated`, `platform.flag_updated`; and in the shop's trail `marketplace.service_payment_started`, `marketplace.service_payment_rejected`, `marketplace.service_payment_unsettled`, `marketplace.subscription_activated`, `marketplace.promotion_activated`.

## 5. Webhook (server-to-server — not for the frontend)

`POST /api/v1/public/marketplace/payments/paystack/webhook` — configure this URL in the Paystack dashboard.

* Authenticated **only** by the `x-paystack-signature` header (HMAC-SHA512 of the raw body with the secret key, constant-time compare). Missing/wrong signature → `401 invalid_signature`, nothing stored, nothing done. No session, no CSRF.
* Only `charge.success` is acted on (other events → `200 ignored`). Even then the **body is not trusted**: the payment is re-read from Paystack and activated only when reference, amount, currency and status all match.
* The delivery is **stored first** and de-duplicated on `(provider, event:reference)`: a redelivery answers `200 duplicate` and can never activate twice. Settlement itself is idempotent (row lock + `settled_at`), so a webhook, a verify call and the reconcile job racing each other grant the benefit **exactly once**.
* A temporary failure (Paystack unreachable, database error) leaves the event `failed` and answers **`500`** so Paystack redelivers; the scheduled `marketplace:reconcile-payments` (every 10 min) also retries unfinished events, re-verifies pending payments from the last 3 days (so a customer who paid but whose webhook never arrived still gets the benefit) and marks payments unpaid after 24 h as `abandoned`.

## 6. Limitations (V1, by design)

* No refunds, proration, plan upgrade mid-period, auto-renewal, invoices/receipts or promotion credits. Refund or dispute handling is manual outside this API.
* A payment that cannot be applied (`needs_attention`) is surfaced to admins but there is no admin "apply"/"refund" action yet.
* Real Paystack calls are exercised against `Http::fake` in tests only; run one live test-mode payment before launch.
