# Phase 25 — Marketplace deal summary & fulfilment

Authoritative contract for the frontend. Design record: [`../implementations/44-PHASE-25-MARKETPLACE-DEALS.md`](../implementations/44-PHASE-25-MARKETPLACE-DEALS.md). Builds on [Phase 24 offers](PHASE-24-MARKETPLACE-OFFERS.md) and [Phase 23 listings](PHASE-23-MARKETPLACE-LISTINGS.md).

**What this is:** a lightweight **deal summary**. Once buyer and seller have *both* said yes to the same terms, the platform records a frozen summary of what they agreed (product, quantity, unit price, product total, how it is handed over, whether delivery is included) and releases each side's contact details to the other, so they can finish the transaction themselves.

**What this is not.** Farmvest is **not an escrow, payment processor or logistics provider.** A deal never collects payment, holds funds, runs a wallet, pays out a seller, refunds, marks anything paid, delivers goods, tracks a shipment or guarantees the outcome. It does **not** reserve or deduct stock and it creates **no** Sale, invoice, payment or finance record. Payment, transport and handover are arranged directly between the two parties. Every deal response carries this as `notice`.

All routes need sign-in + a verified email and **no farm** (no `X-Farm-Id`). Writes use the `marketplace-write` throttle (60/min); contact reads use `marketplace-contact` (30/min). Money and quantities are decimal strings (NGN).

## 1. How a deal comes into existence

A deal needs **two yeses**. Contact is never shared before the second one.

```
A. Negotiated offer                         B. Fixed-price purchase request
 buyer offers  (Phase 24)                    buyer "Proceed at listed price"  (Phase 24 purchase intent)
 seller ACCEPTS (Phase 24)                   seller CONFIRMS  ──▶ confirmation (awaiting_buyer; NO deal, NO contact)
 buyer CONFIRMS  ──▶ DEAL (accepted)         buyer CONFIRMS the exact terms ──▶ DEAL (accepted)
        └ within marketplace_deal_confirmation_hours (72h) of acceptance       └ within the same window of the seller's confirmation
```

* **Only the buyer** turns an accepted offer into a deal. There is no seller-side shortcut.
* A seller's confirmation of a purchase request is **not an agreement**: no deal, no contact, nothing reserved. It is an offer to proceed on *exactly* these terms (the request's quantity at the listing's **current** unit price, the chosen fulfilment method and an optional delivery charge). The deal exists only when the buyer confirms.
* An **unconfirmed purchase intent is never an agreement.** An accepted offer is an agreement *in principle* only.
* Terms are **frozen** on the deal: product, unit, quantity, unit price, product total, fulfilment method and delivery arrangement. Later listing edits never change a deal.
* Every creation is **idempotent**: repeating it returns the same deal (`200`; the first call is `201`). The database refuses a second deal from the same offer or the same confirmation.

## 2. Endpoints

All under `/api/v1/marketplace` unless noted.

### Buyer

| Method | URL | Purpose |
|---|---|---|
| POST | `/my/offers/{offer}/deal` | **A.** Confirm an accepted offer as a deal (`201` / `200`) |
| GET | `/my/deal-confirmations/{confirmation}` | **B.** Read the terms the seller confirmed |
| POST | `/my/deal-confirmations/{confirmation}/confirm` | **B.** Confirm those terms → deal (`201` / `200`) |
| GET | `/my/deals` | Own deals (`status` = `accepted`\|`completed`\|`cancelled`) |
| GET | `/my/deals/{deal}` | One own deal with history and own reports |
| POST | `/my/deals/{deal}/complete` | Report the deal carried out (self-reported) |
| POST | `/my/deals/{deal}/cancel` | Cancel an active deal |
| POST | `/my/deals/{deal}/report` | Report the deal or the seller |
| GET | `/my/deals/{deal}/contact` | The seller's contact (audited) |

### Seller (shop members)

| Method | URL | Permission | Purpose |
|---|---|---|---|
| POST | `/shops/{shop}/purchase-intents/{intent}/confirm` | `deal.respond` | **B.** Confirm a purchase request (`201` / `200`) |
| POST | `/shops/{shop}/deal-confirmations/{confirmation}/withdraw` | `deal.respond` | Withdraw an unanswered confirmation |
| GET | `/shops/{shop}/deals` | `deal.view` | Shop's deals (`status`, `listing`) |
| GET | `/shops/{shop}/deals/{deal}` | `deal.view` | One deal |
| POST | `/shops/{shop}/deals/{deal}/complete` | `deal.respond` | Report the deal carried out (self-reported) |
| POST | `/shops/{shop}/deals/{deal}/cancel` | `deal.respond` | Cancel an active deal |
| POST | `/shops/{shop}/deals/{deal}/report` | `deal.respond` | Report the deal or the buyer |
| GET | `/shops/{shop}/deals/{deal}/contact` | `deal.respond` | The buyer's contact (audited) |

`deal.view`: owner, manager, staff. `deal.respond`: owner, manager (staff get `403`). A shop the caller does not belong to, another shop's deal, and another buyer's deal all answer `404`.

### Platform admin (`/api/v1/platform-admin/marketplace`, any platform role, **read-only**)

| Method | URL | Purpose |
|---|---|---|
| GET | `/deals` | All deals (`status`, `shop_id`, `reported`, `q` = part of the reference) with `report_count` |
| GET | `/deals/{deal}` | Frozen terms, full history (including `reported` events) and every report with its reporter |
| GET | `/deals/{deal}/contact` | Both parties' contact, audited separately; works in any deal state |
| GET | `/deal-reports` | Every report (`status`, `reason`, `deal_id`) |

Admins cannot change a deal here (`POST/PATCH/DELETE` → `405`/`404`).

### Setting (admin: `PUT /platform-admin/settings/marketplace_deal_confirmation_hours`)

Integer 1–720, default **72**. How long the buyer has to confirm after the seller accepts an offer (deadline derived from `responded_at`, so changing the setting moves open deadlines) or after the seller confirms a purchase request (stamped as `expires_at` on the confirmation). `null` = default.

## 3. A. Accepted offer → deal — `POST /my/offers/{offer}/deal`

Body (all optional):

| Field | Meaning |
|---|---|
| `fulfilment_method` | `pickup` \| `seller_delivery`. **Required only when the listing offers both**; otherwise implied by the listing. `422` if the listing does not offer it. |
| `contact_phone` | Optional phone for **this deal only**, shown to the seller after the deal exists. Digits with an optional leading `+`, 7–15 digits. Email is the fallback. |

The deal keeps the offer's agreed `quantity`, `unit_price` and total exactly (`terms_source: "accepted_offer"`, `price_basis: "negotiated"`). Checked under lock, in order:

| `409 code` | When |
|---|---|
| `offer_not_accepted` | the offer is pending / rejected / expired / voided (`details.status`) |
| `deal_window_closed` | past `offer.deal_confirmation.deadline` |
| `shop_not_active`, `listing_unavailable` | shop suspended/closed; listing paused, archived, restricted or deleted — retry when live |
| `deal_terms_stale` | the listing now sells a different product or unit than the one agreed |
| `quantity_unavailable` | the agreed quantity no longer fits the seller-**declared** availability (`details.declared_available`, `details.basis: "seller_declared"`). **Nothing is reserved.** |
| `seller_contact_unavailable` | the shop has no contact channel configured |

The listing's *current price* is deliberately **not** compared: an accepted offer keeps its agreed price. `GET /my/offers/{offer}` (Phase 24 shape) gains, once accepted: `deal` (`{id, reference, status}` or `null`), `deal_confirmation` (`{required_from: "buyer", open, deadline, window_hours}`) and a `next_step` sentence.

## 4. B. Fixed-price purchase request → seller confirmation → buyer confirmation

**Seller — `POST /shops/{shop}/purchase-intents/{intent}/confirm`** (`deal.respond`). Body: `fulfilment_method` (required when the listing offers both) and optional `delivery_charge` (naira, ≤ 2 decimals). Creates a **confirmation** (`reference` `CNF-…`, `status: awaiting_buyer`, `agreement: "none"`, `contact: null`) holding the exact terms. Repeating the *same* confirmation returns the open one (`200`); *different* terms while one is open → `409 confirmation_pending` (`details.confirmation_id`): withdraw first.

| `409 code` | When |
|---|---|
| `intent_stale` | the listed price, unit or product changed since the buyer recorded interest — the buyer must proceed again |
| `intent_already_converted` | the request already became a deal; the buyer must record interest again (possible once that deal is `completed`/`cancelled`) |
| `confirmation_pending` | an open confirmation with different terms exists |
| `shop_not_active`, `listing_unavailable`, `quantity_unavailable` | as in §3 |
| `422` | `fulfilment_method` missing/invalid; `delivery_charge` on a pickup, on a listing whose delivery is *included*, negative or > 2 decimals |

**Buyer — `POST /my/deal-confirmations/{id}/confirm`**. Body: `accept_terms: true` (required) and optional `contact_phone`. Nothing else can be changed: the terms come from the seller's confirmation. Creates the deal (`terms_source: "confirmed_intent"`, `price_basis: "listed"`).

| `409 code` | When |
|---|---|
| `confirmation_lapsed` | past `expires_at` (the lapse is recorded) |
| `confirmation_withdrawn` | the seller took it back |
| `confirmation_voided` | the buyer changed their request after the seller confirmed (`details.reason: "intent_changed"`) |
| `confirmation_stale` | the listed price, unit, product or offered fulfilment changed, or a proposed delivery charge no longer fits the listing; the confirmation is **voided** (persisted) |
| `quantity_unavailable`, `listing_unavailable`, `shop_not_active`, `seller_contact_unavailable` | as in §3; the confirmation is *not* voided (the seller may restore stock / go live again) |

A buyer who changes their request (`POST …/purchase-intent` with a new quantity) **voids** any open confirmation for it. `GET /my/enquiries` and the shop's purchase-intent list now carry, on each purchase intent: `deal_flow` (`awaiting_seller` \| `awaiting_buyer` \| `deal`), `confirmation` (the open one, full terms) and `deal` (`{id, reference, status}`); sellers also get `can_confirm`.

**Withdraw — `POST /shops/{shop}/deal-confirmations/{id}/withdraw`** (`deal.respond`): idempotent; `409 confirmation_not_open` once converted or lapsed.

## 5. The deal object

```json
{
  "id": "…", "reference": "DEL-2026-00001", "status": "accepted", "terms_source": "accepted_offer",
  "source": {"kind": "offer", "id": "…", "reference": "OFR-2026-00001"},
  "listing": {"id": "…", "slug": "…", "reference": "LST-2026-00001", "title": "Healthy point-of-lay chickens", "currently_live": true},
  "shop": {"id": "…", "name": "Ada Poultry Hub", "slug": "ada-poultry-hub"},
  "terms": {"product_name": "Chicken", "product_kind": "livestock", "quantity": "10", "unit": "head", "unit_price": "7000.00",
            "product_total": "70000.00", "currency": "NGN", "listed_unit_price": "8000.00", "price_basis": "negotiated",
            "listing_version": 3, "total_covers": "product_only", "frozen": true},
  "fulfilment": {
    "method": "seller_delivery", "label": "Seller-arranged delivery", "listing_offered": "both",
    "pickup_area": null, "delivery_coverage": ["Oyo", "Lagos"], "dispatch_estimate": {"value": "1_2_days", "label": "1-2 days"},
    "delivery_charge": {"mode": "agreed_separately", "amount": null, "currency": "NGN", "included_in_product_total": false, "display": "To be agreed directly"},
    "arranged_by": "buyer_and_seller"
  },
  "completion": {"verification": "self_reported", "state": "awaiting_both", "buyer_confirmed_at": null, "seller_confirmed_at": null,
                 "completed_at": null, "note": "Completion is reported by each party. Farmvest does not verify that goods or payment changed hands."},
  "cancellation": null, "contact_available": true, "contact": null, "created_at": "…",
  "notice": "This is a summary of terms the buyer and seller agreed between themselves. Farmvest only connects them: …",
  "you": "buyer", "can": {"complete": true, "cancel": true, "report": true, "view_contact": true}
}
```

* **`terms` are frozen.** `product_total` = `unit_price × quantity` (kobo-exact) and covers **the product only**. The delivery charge is **never** part of it.
* **`fulfilment.method`** is the single method agreed. `pickup` → `pickup_area` (general area only) and `delivery_charge.mode: "not_applicable"`. `seller_delivery` → `delivery_coverage`, `dispatch_estimate` and a charge mode: `included` (delivery is in the unit price) or `agreed_separately`.
* **`delivery_charge.amount`** is `null` unless the *seller proposed a figure and the buyer confirmed it* (purchase-request door). `null` is displayed as **"To be agreed directly"** — render `display` as-is; never compute or imply a charge. An accepted offer always has `amount: null`. Neither party can change the charge or any fulfilment term after confirmation.
* **`completion.state`**: `awaiting_both` \| `awaiting_seller` \| `awaiting_buyer` \| `completed` \| `cancelled`. `verification` is **always** `"self_reported"`; Farmvest never verifies offline fulfilment.
* **`can.*`** are role-aware hints (a staff member gets all `false`); the server still enforces.
* Seller/admin views add `buyer: {name}` (admin also `id`); admin views add `report_count`. The detail view adds `history` and `my_reports` (admin: `reports`).
* Money is never added: the API returns `unit_price`, `product_total` and `delivery_charge.amount` separately.

## 6. Completion — two-sided and self-reported

`POST …/complete` (buyer: `/my/deals/{deal}/complete`; seller: `/shops/{shop}/deals/{deal}/complete`).

* Each side reports separately. The deal becomes `completed` **only when both have**; until then it stays `accepted` and `completion.state` says who is awaited. There is **no automatic completion after any period** and no sweep touches a deal.
* Repeating your own report is a no-op (`200`). `409 deal_not_open` if the deal was cancelled.
* Completing records **nothing** in inventory, sales, invoices or finance. Recording the sale on the farm side stays a separate, explicit action (see §10).

## 7. Cancellation

`POST …/cancel` — body `reason` (required code: `changed_mind`, `seller_unavailable`, `no_agreement_on_terms`, `no_response`, `found_alternative`, `other`) and optional `note` (≤ 500).

* Either party, on an **active** (`accepted`) deal, even after one side reported completion. Terminal. Repeating it is a no-op (the first reason stands). `409 deal_not_open` once `completed`.
* **Ends contact access through the API** (`409 deal_contact_unavailable` afterwards) — but details already shared **cannot be recalled**, and the responses say so.
* Restores no stock and refunds nothing, because Farmvest never reserved stock or held money.

## 8. Reports

`POST …/report` — body `target` (`deal` \| `other_party`), `reason` (`no_show`, `misrepresented_product`, `terms_changed`, `abusive_behaviour`, `suspected_fraud`, `other`), optional `description` (≤ 2000).

* Allowed in **any** deal state, including after cancellation or completion.
* **Does not change the deal.** No status change, no freeze of completion or cancellation, no refund, penalty or settlement. The report preserves the deal and its history as they were and records `deal_status_at_report`.
* Phase 27: one active report per reporter, target and issue (`reason`); repeating returns the active case (`200`; first `201`). Closing permits a later new case. The report reference is `DRP-…`.
* **Confidential:** the other party never sees that they were reported — not in `my_reports`, not in `history`. The reporter sees their own. Platform admins see all.
* Phase 27 implements triage, outcomes and explicitly selected enforcement; see `PHASE-27-MARKETPLACE-SAFETY.md`. A complaint or outcome alone never changes the deal.

## 9. Contact exchange and privacy

Contact appears in **no** list, detail, offer, enquiry, confirmation or public payload — only in the dedicated `…/contact` endpoints, and only after a deal exists.

| Reader | Receives |
|---|---|
| Buyer | the shop's name, `preferred_contact_method`, and the **configured** channels (`phone`, `whatsapp`, `email`). The exact `address_line` **only when the deal is a pickup**; a delivery deal gets `delivery.coverage` and `dispatch_estimate` instead and never the private address. |
| Seller (`deal.respond`) | the buyer's display `name`, account `email`, and the optional `phone` the buyer gave for **this deal**. `preferred_contact_method` is `phone` if given, else `email`. **No phone is assumed or stored on the profile.** |
| Platform admin | both parties, audited separately, any deal state |

```json
{"data": {"deal": {"id": "…", "reference": "DEL-2026-00001", "status": "accepted", "fulfilment_method": "pickup"},
          "party": "seller", "notice": "Arrange payment, transport and handover directly … Details already shared cannot be recalled.",
          "contact": {"name": "Ada Poultry Hub", "preferred_contact_method": "whatsapp",
                      "channels": {"phone": "+2348031234567", "whatsapp": "+2348099998888", "email": "ada.private@example.com"},
                      "pickup": {"address_line": "12 Secret Street, Bodija", "area": "Bodija market"}}}}
```

* Available while the deal is `accepted` or `completed`; `409 deal_contact_unavailable` once cancelled (admins excepted).
* The seller's contact is read **live** from the shop, so it is current.
* **Audited:** every read writes an append-only contact-access record (viewer, side, **field names only**) and an audit entry `marketplace.deal_contact_viewed` (admin: `platform.marketplace_deal_contact_viewed`). Values are never logged. Refused attempts (wrong user, staff) disclose nothing and write no record.
* Rate limit `marketplace-contact` (30/min per user) — do not poll this endpoint; fetch once when the user opens the contact panel.

## 10. Boundaries (what a deal never does)

* **No payment**: no collection, escrow, wallet, payout, refund or "paid" flag. No `payment_status` exists.
* **No inventory**: no reservation, deduction, restoration or movement. Availability is **seller-declared**; the deal only checks the quantity still fits the declared figure at confirmation. It is not a promise.
* **No Sale / invoice / finance**: a deal posts nothing to the Sales or Finance modules. *Future integration boundary (not built):* an authorised farmer explicitly records the sale with the existing `POST /sales`, using an idempotency key derived from the deal (`deal:{deal_id}`). `sales` is unique on `(farm_id, idempotency_key)`, so a repeat is a replay and never a second sale. Nothing here posts automatically.
* **No logistics**: no courier, tracking, milestones or Farmvest delivery. `seller_delivery` means the **seller** arranges delivery; the buyer and seller settle the charge directly.

## 11. Lifecycle

```
(deal created) accepted ──both sides report──▶ completed   (terminal)
                  │      ──either party──────▶ cancelled   (terminal, contact ends)
                  └ reports never change the status
confirmation: awaiting_buyer ─buyer▶ converted | ─seller▶ withdrawn | ─time▶ lapsed | ─change▶ voided
```

Expiry of confirmations is enforced on read (reported `lapsed`) and on write; the hourly `marketplace:expire-confirmations` command persists it and is safe to repeat. No command ever alters a deal.

## 12. Errors

| HTTP | `code` | When |
|---|---|---|
| 403 | `forbidden` | staff on a `deal.respond` action / contact |
| 404 | `not_found` | another buyer's offer/deal/confirmation, a shop the caller is not in, another shop's deal |
| 409 | `offer_not_accepted`, `deal_window_closed`, `deal_terms_stale` | confirming an accepted offer |
| 409 | `intent_stale`, `intent_already_converted`, `confirmation_pending`, `confirmation_not_open` | seller side of a purchase request |
| 409 | `confirmation_lapsed`, `confirmation_withdrawn`, `confirmation_voided`, `confirmation_stale` | buyer confirming |
| 409 | `shop_not_active`, `listing_unavailable`, `quantity_unavailable`, `seller_contact_unavailable` | both doors |
| 409 | `deal_not_open` | complete a cancelled deal; cancel a completed one |
| 409 | `deal_contact_unavailable` | contact of a cancelled deal |
| 422 | `validation_failed` | `fulfilment_method`, `delivery_charge`, `contact_phone`, `accept_terms`, `reason`, `target`, `note`, `description` |
| 429 | — | `marketplace-write` / `marketplace-contact` throttles |

## 13. Side effects and audit

Append-only deal history (`created`, `completion_confirmed`, `completed`, `cancelled`, `reported`), contact-access log, and audit entries `marketplace.deal_confirmation_created|withdrawn|lapsed|voided|converted`, `marketplace.deal_created`, `marketplace.deal_completion_confirmed`, `marketplace.deal_completed`, `marketplace.deal_cancelled`, `marketplace.deal_reported`, `marketplace.deal_contact_viewed`, `platform.marketplace_deal_contact_viewed`. Audit rows carry ids, references, codes and **field names — never contact values**. **No inventory, finance, sales or notification side effects** (verified by a whole-database row-count test).

## 14. Concurrency guarantees

* Database unique keys: one deal per offer (`offer_id`), one per confirmation (`confirmation_id`), a `CHECK` that a deal has exactly one source, one open confirmation per intent (`open_slot`), one active report per reporter, target and issue (Phase 27).
* Lock order: buyer paths lock **listing → offer/intent → confirmation**; the seller path locks **shop → listing → intent → confirmation** (the order Phase 24 uses); deal actions lock **only the deal row**. No cycle is possible.
* Verified with real multi-process tests: simultaneous confirmations of one offer or confirmation → one deal; simultaneous seller confirmations → one open confirmation; both sides completing together → completed exactly once; cancel racing complete → exactly one terminal outcome; a buyer changing their request racing the buyer's confirmation never leaves a mismatched confirmation.
