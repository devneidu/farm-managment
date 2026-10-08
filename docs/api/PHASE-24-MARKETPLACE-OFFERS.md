# Phase 24 — Buyer enquiries & controlled negotiation

Authoritative contract for the frontend. Design record: [`../implementations/43-PHASE-24-MARKETPLACE-OFFERS.md`](../implementations/43-PHASE-24-MARKETPLACE-OFFERS.md).

**What this is:** a small, controlled offer workflow on Marketplace listings. A buyer either **proposes a lower unit price** (an *offer*) or **records interest at the listed price** (a *purchase intent*). The shop's owner or manager accepts or rejects offers.

**What this is not:** chat, escrow, checkout, an order, a deal or a sale. Nothing is reserved, charged, deducted or posted. **Seller contact details are never returned in this phase** — not even after acceptance. Contact exchange, deal confirmation and availability confirmation are Phase 25.

All routes are under `/api/v1/marketplace`, need sign-in + a verified email, **no farm**, and the write routes use the `marketplace-write` throttle (60/min). Money and quantities are decimal strings (NGN).

## 1. Endpoints

| Method | URL | Who | Purpose |
|---|---|---|---|
| POST | `/listings/{slug}/offers` | any signed-in user outside the shop | Make an offer (`201`) |
| GET | `/listings/{slug}/offer-status` | any signed-in user | Attempts, floor, expiry, whether an offer is possible |
| POST | `/listings/{slug}/purchase-intent` | any signed-in user outside the shop | "Proceed at listed price" (`201` first time, `200` repeat) |
| GET | `/my/enquiries` | buyer | Own offers + purchase intents, newest first |
| GET | `/my/offers/{offer}` | buyer | One own offer with history |
| GET | `/shops/{shop}/offers` | `offer.view` | Shop's offers |
| GET | `/shops/{shop}/offers/{offer}` | `offer.view` | One offer with history |
| POST | `/shops/{shop}/offers/{offer}/accept` | `offer.respond` | Accept |
| POST | `/shops/{shop}/offers/{offer}/reject` | `offer.respond` | Reject |
| GET | `/shops/{shop}/purchase-intents` | `offer.view` | Recorded purchase intents |

Permissions: `offer.view` — owner, manager, staff. `offer.respond` — owner, manager (staff get `403` on accept/reject). A shop the caller does not belong to, and another user's offer, answer `404`.

## 2. Platform settings (admin: `PUT /platform-admin/settings/{key}`)

| Key | Range | Default | Meaning |
|---|---|---|---|
| `marketplace_max_offers_per_buyer` | integer 1–10 | 3 | Offers per buyer per listing. Pending, accepted, rejected and expired count; **voided do not**. |
| `marketplace_offer_expiry_hours` | integer 1–720 | 48 | How long a pending offer stays open. |
| `marketplace_min_offer_percent` | 1–99, ≤ 2 decimals | 70 | Floor as a percentage of the **listed unit price** (not the order total). |

Settings are read live at submit time. `null` means "use the default".

## 3. Making an offer — `POST /listings/{slug}/offers`

Body: `quantity` (decimal string) and `unit_price` (naira per ONE unit, ≤ 2 decimals).

Validation, **in this order, all before an attempt is used**:

1. Listing is public (published listing of an active shop) — else `404`.
2. Listing is negotiable — else `409 listing_not_negotiable`.
3. Caller is not a member of the shop or the owner of its linked farm — else `403 cannot_negotiate_own_listing`.
4. `quantity`: unit precision, minimum order, declared availability — else `422` on `quantity`.
5. `unit_price`: `floor ≤ unit_price < listed_unit_price`, where `floor = listed × min_percent / 100` (exact decimals, rounded **up** to the kobo for display). Else `422` on `unit_price` with the lowest accepted price in the message. To buy at the listed price use *Proceed at listed price*.

Then, under a lock: lapsed offers are settled, and — `409 offer_already_accepted`, `409 offer_pending` (`details.offer_id`), `409 offer_limit_reached` (`details.max_attempts`, `details.attempts_used`).

Response `201` — an **offer** object (§6). `expires_at` is stamped at submit.

## 4. Offer lifecycle

```
pending ──seller──▶ accepted   (terminal)
   │    ──seller──▶ rejected   (terminal, uses an attempt)
   │    ──time────▶ expired    (terminal, uses an attempt)
   └────listing edit▶ voided   (terminal, does NOT use an attempt)
```

* **Expiry** is enforced on read (a pending offer past `expires_at` is *reported* `expired`) and on write (every write path first persists due expiries). The hourly command `marketplace:expire-offers` keeps stored status in step; it is safe to run any number of times.
* **Seller decisions** are idempotent: repeating the decision that already won returns `200` unchanged. The opposite decision is `409 offer_not_pending`. A lapsed offer is `409 offer_expired` (the lapse is recorded). An invalidated offer is `409 offer_voided`.
* **Accept** additionally needs the listing to be `published` (`409 listing_unavailable` while paused / archived / restricted — retry once live), the shop `active` (`409 shop_not_active`) and the offer's snapshot to still match the listing (otherwise the offer is voided and `409 offer_voided` returned). **Reject** is allowed whenever the offer is pending, even while the listing is paused.
* **Voiding.** When a seller edits a listing, its pending offers are voided (`void_reason: "listing_changed"`) if the edit changed the **unit price, unit, product identity, negotiability**, lowered the **available quantity below the offer quantity**, or raised the **minimum order above the offer quantity**. Other edits (title, description, fulfilment, a larger availability…) leave offers alone. Pausing, archiving, restricting or suspending the shop never mutates offers: accept is simply blocked until the listing is live again, and expiry keeps running.
* **Accepted offers are immutable snapshots.** Later listing changes never alter them. Accepting reserves **no** stock and guarantees **no** availability; Phase 25 must confirm availability before any deal.
* No buyer withdrawal in this phase: a pending offer ends by seller decision or expiry.

## 5. Purchase intent — `POST /listings/{slug}/purchase-intent`

Body `{quantity}`. Records interest at the listed unit price. Works on negotiable and fixed-price listings, also after the buyer used all offers. Same `404` / `403` / `422 quantity` rules. **Idempotent** per buyer and listing: same quantity → `200` same record; different quantity → `200` refreshed record; first call → `201`. `is_current` is computed on read (false if the price or unit changed or the listing is not live). It is *not* an acceptance, payment, order or deal; the seller must still confirm in Phase 25.

## 6. Response shapes

Offer (buyer and seller views; seller view adds `buyer` and `respondable`; `history` only on detail/submit/decision responses):

```json
{
  "id": "…", "reference": "OFR-2026-00001", "kind": "offer", "status": "pending", "attempt_no": 1,
  "listing": {"id": "…", "slug": "healthy-point-of-lay-chickens-ab12cd", "reference": "LST-2026-00001", "title": "…", "currently_live": true},
  "shop": {"id": "…", "name": "Ada Poultry Hub", "slug": "ada-poultry-hub"},
  "terms": {"quantity": "10", "unit": "head", "unit_price": "7000.00", "total": "70000.00", "currency": "NGN"},
  "listing_snapshot": {"title": "…", "product_name": "Chicken", "product_kind": "livestock", "unit": "head", "listed_unit_price": "8000.00",
    "listing_version": 3, "available_quantity": "120", "min_order_quantity": "5", "basis": "seller_declared_at_offer_time"},
  "expires_at": "2026-10-10T09:00:00+00:00", "responded_at": null, "void_reason": null, "created_at": "…",
  "agreement": "none", "contact": null,
  "buyer": {"name": "Bola Buyer"}, "respondable": true,
  "history": [{"action": "submitted", "actor": "buyer", "from": null, "to": "pending", "code": null, "at": "…"}]
}
```

`status` is the **effective** status. `agreement` is `seller_accepted` only when accepted. `contact` is always `null` in Phase 24; accepted offers add a `next_step` sentence. The seller sees the buyer's **display name only** (no id, email or phone).

Purchase intent: `kind: "purchase_intent"`, `status: "recorded"`, `agreement: "none"`, `terms` (listed price), `is_current`, `listing_version`, `note`, `contact: null`.

`GET /my/enquiries` mixes both; discriminate on `kind`. Filters: `kind`, `status` (offers; `expired` includes lapsed pending), `page`, `per_page` ≤ 50.

`GET …/offer-status`:

```json
{"listing": {"id": "…", "slug": "…", "negotiable": true, "unit_price": "8000.00", "currency": "NGN"},
 "rules": {"max_attempts": 3, "attempts_used": 1, "attempts_remaining": 2, "min_offer_percent": "70", "minimum_unit_price": "5600.00", "offer_expiry_hours": 48},
 "can_offer": false, "blocked_reason": "offer_pending",
 "pending_offer": {"id": "…", "reference": "OFR-2026-00001", "expires_at": "…"}, "offers": [{"id": "…", "reference": "…", "attempt_no": 1}], "purchase_intent": null}
```

`blocked_reason` ∈ `listing_not_negotiable | offer_already_accepted | offer_pending | offer_limit_reached | null`.

## 7. Errors

| HTTP | `code` | When |
|---|---|---|
| 403 | `cannot_negotiate_own_listing` | shop member / linked farm owner |
| 403 | `forbidden` | staff accepting or rejecting |
| 404 | `not_found` | non-public listing, other shop's / other user's offer |
| 409 | `listing_not_negotiable`, `offer_pending`, `offer_limit_reached`, `offer_already_accepted` | submit |
| 409 | `offer_expired`, `offer_voided`, `offer_not_pending`, `listing_unavailable`, `shop_not_active` | accept / reject |
| 422 | `validation_failed` | `quantity`, `unit_price` (floor / ceiling / precision) |

## 8. Side effects and audit

Offers write append-only history (`submitted`, `accepted`, `rejected`, `expired`, `voided`) and audit entries `marketplace.offer_submitted|accepted|rejected|expired|voided`, `marketplace.purchase_intent_recorded`. Buyer-side entries carry no farm; seller-side entries carry the shop's linked farm id. **No inventory, finance, sales or notification side effects.**

## 9. Concurrency guarantees

Database unique keys allow only one pending offer per buyer and listing, and one use of each attempt number. Buyer writes lock the listing row; seller decisions lock shop → listing → offer (the order listing edits use); the expiry sweep locks one offer at a time. Verified with real multi-process tests (simultaneous submissions; simultaneous accept/reject).
