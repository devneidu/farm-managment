# Phase 25 — Marketplace deal summary & fulfilment (design record)

Contract: [`../api/PHASE-25-MARKETPLACE-DEALS.md`](../api/PHASE-25-MARKETPLACE-DEALS.md). Continues Phase 24 (offers). Phase 26 (monetisation) and Phase 27 (moderation) are **not started**.

Farmvest is a connector. It is **not** an escrow, payment processor or logistics provider.

## Decisions (approved)

1. **Accepted-offer deals: the buyer confirms, nobody else.** The seller already accepted in Phase 24, so there is no seller-triggered creation. The buyer must confirm within `marketplace_deal_confirmation_hours` (default 72) of acceptance; the deadline is derived from `responded_at`.
2. **Fixed-price intents: seller first, buyer last.** The seller's confirmation is a separate record (`marketplace_deal_confirmations`) that exposes no contact and is not an agreement. The deal exists, and contact is released, only when the buyer confirms the exact terms. Lightweight and idempotent; duplicates impossible.
3. **Completion: both sides self-report.** No automatic completion after any period. One side confirming leaves the deal open and exposes `completion.state` (`awaiting_seller` / `awaiting_buyer`). Always labelled `self_reported`.
4. **Buyer contact:** no mandatory phone and no profile field. An optional `contact_phone` is supplied per deal at confirmation (validated), email is the fallback; visible only to the authorised side after activation.
5. **Pickup address:** the private `address_line` is disclosed only when the confirmed deal is a pickup. A delivery deal exposes only the channels and the general coverage. No private address is in any list/detail payload.
6. **Delivery terms:** neither party can change delivery charges or fulfilment terms after confirmation (there is no edit endpoint). An unknown charge is `null`, displayed "To be agreed directly", and never added to the product total. A figure exists only if the seller proposed it and the buyer confirmed it (purchase-request door).
7. **Reports are separate from lifecycle.** A report never changes the deal's status or freezes completion/cancellation. Reports and the append-only deal history preserve the complaint; Phase 27 owns moderation.
8. **Cancellation:** either party, active deal, reason code. Ends contact access through the API; previously shared details cannot be recalled and the responses say so. No refund, stock restoration or payment action.
9. **Immutable commercial terms.** Product, unit, quantity, unit price and total are copied onto the deal. Listing and seller-declared availability are validated before activation.
10. **Boundaries:** no stock reservation/deduction, Sale, invoice, payment, escrow, wallet, payout, refund or Farmvest delivery.
11. **Security:** row locking, DB uniqueness, idempotency, authorization and contact-access auditing; tested with real parallel processes.

## Data model (migration `2026_10_22_100000_create_marketplace_deals`)

* `marketplace_deal_confirmations` — the seller's confirmation of a purchase intent (`CNF-…`): frozen quantity, unit price, total, fulfilment method, optional delivery charge and a listing snapshot; `status` (`awaiting_buyer|converted|withdrawn|lapsed|voided`), `expires_at`, `open_slot` ('O' while open, else NULL; unique with `intent_id` = one open confirmation per intent).
* `marketplace_deals` (`DEL-…`) — exactly one source: `offer_id` (unique) or `confirmation_id` (unique), enforced by a `CHECK` (`marketplace_deals_one_source`). Frozen product/unit/quantity/`unit_price`/`product_total`/`listed_unit_price`, fulfilment snapshot (`fulfilment_method`, `listing_fulfilment`, `pickup_area`, `delivery_coverage`, `dispatch_estimate`, `delivery_charge_mode`, nullable `delivery_charge_amount`), optional `buyer_contact_phone`, per-side completion timestamps, cancellation facts. Status `accepted|completed|cancelled`.
* `marketplace_deal_events` — append-only history (model refuses update/delete).
* `marketplace_deal_reports` (`DRP-…`) — reporter, side, target (`deal|buyer|seller`), reason, optional description, `status` (`open`), `deal_status_at_report`. Unique `(deal_id, reporter_id, target)`. No resolution columns yet (Phase 27).
* `marketplace_deal_contact_views` — append-only access log (viewer, side, field **names**).
* `marketplace_purchase_intents.converted_at` — set when the intent became a deal; cleared when the buyer proceeds again after that deal finished.

No link to inventory, finance or sales exists.

## Services

* `MarketplaceDealService` — confirm accepted offer, seller confirm / withdraw, buyer accept, sweep, availability and staleness checks.
* `MarketplaceConfirmationLifecycle` — the only place a confirmation leaves `awaiting_buyer`.
* `MarketplaceDealLifecycle` — complete, cancel, report (locks only the deal row).
* `MarketplaceDealContact` — the only door for contact; records and audits every read.
* `MarketplaceDealDirectory` — read side for buyers, shops and admins.
* `MarketplaceReferences` — `GET_LOCK`-guarded reference numbering.
* Phase 24 touch-points: `MarketplaceOfferService::recordIntent` voids open confirmations when the buyer changes the request and re-arms a finished intent; `OfferResource` / `PurchaseIntentResource` expose `deal`, `deal_confirmation`, `deal_flow`.

## Locking

Buyer paths: listing → offer/intent → confirmation. Seller confirm: shop → listing → intent → confirmation (Phase 24's order). Deal actions: the deal row only. Failures that must persist something (a lapse, a void) are raised after the transaction commits. Unique keys back the locks.

## Sales / inventory boundary

A deal posts nothing. Sales is unique on `(farm_id, idempotency_key)`, so a later, explicit integration (a farmer records `POST /sales` with `idempotency_key = "deal:{deal_id}"`) is replay-safe against double posting. Not built; not automatic.

## Phase 27 boundary

Reports are stored and shown read-only to admins. No assignment, notes, outcomes, sanctions, suspension or dispute settlement exists; the report row has only the data Phase 27 needs to extend.
