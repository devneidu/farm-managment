# Phase 26 — Marketplace monetisation (design record)

Contract: [`../api/PHASE-26-MARKETPLACE-MONETISATION.md`](../api/PHASE-26-MARKETPLACE-MONETISATION.md). Status: implemented, uncommitted.

## Goal and boundaries
Let Farmvest earn from marketplace sellers through (1) prepaid seller plans that raise the published-listing allowance and (2) fixed-price promotions that give one listing priority placement and a "Sponsored" label. Out of scope by decision: commissions, escrow, wallets, payouts, buyer–seller payments, stock deduction, bidding/targeting/ad engine, auto-renewal, saved cards, promotion credits bundled into plans, multi-gateway abstraction.

## Decisions
1. **Paystack only**, one small `PaystackClient` (initialize, verify, signature check). No gateway interface: a second provider would justify one.
2. **Subscriptions belong to a shop**, not a user or farm. The Phase 3 farm subscription system was *not* reused: it is farm-scoped and a marketplace seller may have no farm (`marketplace_seller_plans`, `marketplace_shop_subscriptions` are separate tables).
3. **Prepaid 30/365-day periods**; one `marketplace_shop_subscriptions` row per payment (`payment_id` unique). A renewal's `starts_at` is the latest `ends_at` of the shop, so periods chain and never overlap.
4. **Free plan = a seeded row, limit 10**; paid plans seeded **inactive/unpriced/unlimited-unset** so nothing is sold until an admin configures it. Free limit is editable while flags are off.
5. **Over-limit policy**: existing listings untouched. Only `publish` is gated (`published_count >= limit` → `409 listing_limit_reached`). Restore goes to draft so it does not consume a place.
6. **Plan resolved at read time**: effective plan = running paid period, else free. No expiry job. A period freezes `listing_limit` at purchase.
7. **Promotions** are separate rows (`marketplace_promotions`), `open_slot='A'` + unique `(listing_id, open_slot)` = one live promotion per listing at the database level (Phase 25 pattern). Expiry is read from `expires_at`; the slot of an expired promotion is released lazily when the next one is settled.
8. **Ranking**: `MarketplacePromotionRanking` pulls up to `cap` running-promoted listings (filters applied, daily MD5 rotation) to page 1 of the `newest`/`relevance` sorts and paginates the organic remainder manually, so totals and page contents stay consistent and nothing is dropped. Price sorts are pure. The label is attached to every running promotion regardless of sort.
9. **Flags** `marketplace_seller_plans`, `marketplace_promotions` (created disabled by the migration) gate *new checkouts only*; paid benefits keep working until they end. Cap is the platform setting `marketplace_max_promoted_per_page`.
10. **Payment safety** — see below.

## Payment safeguards
* `marketplace_service_payments`: reference `MSP-yyyy-nnnnn` (also the Paystack reference), amount/currency **frozen at checkout**, status `pending|paid|failed|abandoned`, `settled_at` written once.
* `ServicePaymentSettler::settle` is the only grant path. It calls Paystack `verify` server-side, requires `status=success`, echoed `reference`, `currency` and exact kobo `amount` (`bcmul`, no floats). Mismatch → `failed` with a code, no grant.
* Grant runs in a transaction that `lockForUpdate`s the payment, then the shop (serialises renewals) and (promotions) the listing, re-checks `settled_at`, then writes benefit + `settled_at`. Unique `payment_id` on subscription/promotion rows is the backstop.
* A confirmed payment that cannot be applied (suspended shop, restricted/archived listing, listing already promoted, different plan running) is saved `paid` with `settlement_issue` and **no benefit** — visible to admins, retried by later settles, never silently lost or converted.
* Webhook: HMAC-SHA512 over the raw body; delivery stored first (`marketplace_payment_webhook_events`, unique `(provider, dedupe_key)`); body never trusted; failures answer 500 + stay `failed`; `marketplace:reconcile-payments` (10 min) retries events, re-verifies recent pending payments, abandons stale ones. Verify endpoint is the redirect-return path and re-verifies.
* Provider outage ⇒ `PaymentGatewayException` ⇒ nothing changes (no payment is failed because Paystack was down).
* A real race found while testing: a plain read earlier in the request pinned the MySQL snapshot, so the publish-limit count missed commits made while waiting for the shop lock. The count under the lock is now a locking read (`MarketplaceMonetisationConcurrencyTest` guards it).

## Authorisation
New shop permissions `billing.view`, `billing.manage` (owner, manager). Plan/allowance/catalogue need only `listing.view`. A non-member gets 404; another shop's payment/listing 404. Platform: reads any role, writes `admin` role + throttle + `PlatformAudit`.

## Tests
`MarketplaceSellerPlanTest` (14), `MarketplacePaymentSettlementTest` (10), `MarketplacePromotionTest` (9), `MarketplaceMonetisationConcurrencyTest` (3, real forked processes), `ApiDocumentationTest::test_phase_26…` (1).
