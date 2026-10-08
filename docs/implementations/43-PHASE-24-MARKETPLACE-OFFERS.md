# Phase 24 — Buyer enquiries & controlled negotiation (design record)

Contract: [`../api/PHASE-24-MARKETPLACE-OFFERS.md`](../api/PHASE-24-MARKETPLACE-OFFERS.md). Continues Phase 23 (listings). Phase 25 (deal summary, contact exchange) is **not started**.

## Decisions (approved)

1. Voided offers (invalidated by material listing changes) do not use an attempt; history and reason are kept.
2. Seller contact stays hidden throughout Phase 24, including after acceptance. Contact exchange is Phase 25.
3. `offer.view` for owner/manager/staff; `offer.respond` for owner/manager only.
4. No buyer withdrawal/cancellation; pending offers expire.
5. Accepted offers are terminal agreements in principle; Phase 25 owns deals, confirmation and any validity policy.
6. Expiry default 48 h (platform setting), enforced on read and write; expired offers use an attempt.
7. `marketplace_min_offer_percent` (default 70) is the floor on the **unit** price; the offer must also be strictly below the listed price; exact decimals; validated before an attempt is used.
8. "Proceed at listed price" records interest only (a purchase intent).
9. Accepted offers are immutable snapshots; no stock reservation or availability guarantee.
10. Concurrency: DB-enforced single pending offer, row locks, attempt limit, terminal-state protection; tested with real parallel processes.
11. Material listing edits void pending offers; pause/restrict/suspend do not mutate offers but block acceptance.

## Data model

* `marketplace_offers` — terms (`quantity`, `unit_price`, `total_amount`), snapshot of the listing (title, version, listed price, unit, product, availability, minimum order), `attempt_no`, `status`, `pending_slot` (`'P'` only while pending, else NULL), `expires_at`, `responded_at/by`, `void_reason`. Unique `(listing_id, buyer_id, pending_slot)` and `(listing_id, buyer_id, attempt_no)`.
* `marketplace_offer_events` — append-only history (model refuses update/delete).
* `marketplace_purchase_intents` — one per `(listing_id, buyer_id)`, snapshot of the listed price at the last record.

No link to inventory, finance or sales exists.

## Services

* `MarketplaceOfferService` — submit, accept, reject, purchase intent, expiry sweep, settings access, price-floor math.
* `MarketplaceOfferLifecycle` — the only place an offer leaves `pending` (history, audit, `pending_slot` release); `stillValid()` snapshot-vs-listing test; `reconcile()` called by `MarketplaceListingService::update` under the listing lock.
* `MarketplaceOfferDirectory` — read side: buyer status, enquiries (SQL union of offers and intents, hydrated), shop lists.
* `MarketplaceListingRules::orderableQuantity()` — shared quantity validation (estimate, offer, intent).

## Locking

Buyer: global `GET_LOCK` for reference allocation → transaction → lock listing row → the buyer's offers on it. Seller: shop → listing → offer. Listing edit: shop → listing → pending offers. Sweep: one offer row. No cycle exists. Failures that must persist a change (a lapse, a void) are raised after the transaction commits.

## Phase 25 boundary

`marketplace_deals` will reference `offer_id` or `intent_id`. Phase 25 owns Deal Summary, contact exchange, agreement validity, availability confirmation and any Sale (explicit `POST /sales` with a deal-derived idempotency key, per Phase 23 §6.1).
