# Farm Management SaaS --- Complete Engineering Blueprint

**Version:** Engineering Pack v1 • September 2026\
**Product:** Nigeria-first mixed-farm operations SaaS\
**Implementation posture:** API-first, Laravel backend, separate
responsive web/mobile-capable frontend.

> This pack consolidates the product requirements, reference-app
> observations, screenshots and product decisions discussed so far.
> Routes, endpoints, table names and service names in this engineering
> pack are **proposed implementation contracts** derived from those
> requirements; they are not claimed to be URLs observed in the
> reference application.

## Locked product principles

-   Customer mental model is **Account → Farm**. Tenant/account
    isolation may exist internally but no "Organization Name" onboarding
    step.
-   Email signup: email + phone + password + confirmation → email OTP →
    mandatory farm setup.
-   Google auth: Google authentication → no application OTP → mandatory
    farm setup.
-   Mandatory setup asks only **Farm Name** and **Farm Operations**;
    Nigeria/NGN/Africa-Lagos/English are defaults.
-   Mixed farming is supported: livestock, poultry, fishery and crops.
-   Record real-world events once; derive population, inventory,
    finance, analytics and audit effects safely.
-   Livestock batches use initial head/population. Crop projects use
    required **planting units**, not exact seed count.
-   Measurements/conversions are first-class and farm configurable.
-   Schedules/tasks describe planned work; operational records describe
    what actually happened.
-   Dynamic forms are metadata/capability driven, not scattered
    `if chicken` frontend logic.
-   Dashboard/navigation are role-aware and operation-aware.
-   Important actions are auditable; balances/population must be
    explainable.
-   Subscription entitlements are separate from RBAC and must not
    hard-code plan names.

## Implementation sequence

**Phase 0 --- Foundation & engineering guardrails**\
Laravel/API baseline, environments, DB, queues, storage, API response
conventions, IDs, timestamps, test harness, CI, code quality, docs.

**Phase 1 --- Identity, authentication, hidden tenancy, farm setup**\
Registration, OTP, Google, login/reset, tenant creation, mandatory farm
setup, Nigeria defaults, onboarding gate.

**Phase 2 --- RBAC, team and farm settings**\
Memberships, invitations, Farm Admin/Owner, Manager, Finance, Farm
Worker; policies; minimal settings.

**Phase 3 --- Subscriptions & entitlements foundation**\
Plan configuration and capability/limit service. Commercial numbers
remain configurable.

**Phase 4 --- Master data, operation/species/crop capabilities**\
Operations, species, breeds/custom breeds, crops, record
schemas/capabilities.

**Phase 5 --- Measurements, units and conversions**\
Dimensions, base units, package conversions, compound quantities.

**Phase 6 --- Locations, production areas and storage**\
Pens/houses/ponds/fields/plots/stores.

**Phase 7 --- Production cycles**\
Livestock/fish batches and crop projects, initial baselines, lifecycle.

**Phase 8 --- Operational record engine + population**\
Dynamic record schemas, attachments,
mortality/feed/water/weight/egg/milk/crop activity, population
movements.

**Phase 9 --- Inventory, feed and farm inputs**\
Items, lots, movements, feed formulas, stock in/out, low-stock.

**Phase 10 --- Health and medicine**\
Health records, medicine packaging, dose, withdrawal, stock effects.

**Phase 11 --- Breeding and reproduction**\
Incubation/pregnancy dynamic flows, milestones, outcomes, offspring.

**Phase 12 --- Work templates, schedules, tasks and calendar**\
Platform/farm templates, cycle application, recurring schedules, task
evidence.

**Phase 13 --- Crop operations and production outputs**\
Crop establishment checks,
fertilizer/pesticide/irrigation/weeding/loss/harvest; output inventory.

**Phase 14 --- Contacts, purchasing and finance**\
Contacts, expenses/income, purchases, operational cost allocation.

**Phase 15 --- Sales, invoices and payments**\
Sale side effects, invoices distinct from sales, payment tracking,
PDF/share.

**Phase 16 --- Dashboard and deterministic insights**\
Role/operation-aware command center, alerts, calendar, recent activity.

**Phase 17 --- Reports, exports, notifications and audit UX**\
Reports, PDF/XLSX/CSV, notifications/preferences, audit views.

**Phase 18 --- Platform administration**\
Master data, templates, plans/entitlements, settings, feature flags,
support/audit.

**Phase 19 --- Localization and accessibility hardening**\
Translation infrastructure, Nigerian language packs as validated,
responsive/accessibility QA.

**Phase 20 --- Integrations (Deferred from V1 / post-launch enhancement)**\
WhatsApp reports/alerts, AI draft parsing, provider abstractions.

**Phase 21 --- Performance, security, reconciliation and launch**\
Load/index review, security review, backups, observability, data
reconciliation, deployment runbook.

Do not combine phases merely to reduce file count. Each phase must
finish migrations/models/services/API/policies/tests before moving on.

## Post-V1 — Phase 22 (Marketplace foundation & seller shops)

Implemented as a post-launch addition: seller-shop onboarding, private contact configuration, publishing lifecycle, verification, shop members, public discovery and platform oversight. Listings, negotiation, deals, payments and delivery are later phases. See [41-PHASE-22-MARKETPLACE.md](41-PHASE-22-MARKETPLACE.md) and [`docs/api/PHASE-22-MARKETPLACE.md`](../api/PHASE-22-MARKETPLACE.md).

## Post-V1 — Phase 23 (Marketplace product listings, pricing & images)

Implemented as a post-launch addition: seller product listings (master-data products, flexible NGN-priced selling units, seller-declared packages, negotiable flag as data only, seller-arranged fulfilment), an illustrative image catalogue plus seller photos, an optional informational inventory link, a listing lifecycle with immediate publishing for active shops, the anonymous feed and platform-admin restrict/lift. Offers/negotiation (Phase 24), deals (25), monetisation (26), community (28), payments, logistics and stock reservation are later phases. See [42-PHASE-23-MARKETPLACE-LISTINGS.md](42-PHASE-23-MARKETPLACE-LISTINGS.md) and [`docs/api/PHASE-23-MARKETPLACE-LISTINGS.md`](../api/PHASE-23-MARKETPLACE-LISTINGS.md).

## Post-V1 — Phase 24 (Buyer enquiries & controlled negotiation)

Offers on negotiable listings (price floor, attempt limit, expiry, accept/reject), "proceed at listed price" purchase intents, shop permissions `offer.view|respond`. No chat, escrow, checkout, stock reservation or seller contact exchange. Design: `43-PHASE-24-MARKETPLACE-OFFERS.md`; contract: `docs/api/PHASE-24-MARKETPLACE-OFFERS.md`. Phase 25 (deals, contact exchange) is implemented below.

## Post-V1 — Phase 25 (Marketplace deal summary & fulfilment)

A lightweight deal summary: the buyer confirms an accepted offer (A), or the seller confirms a fixed-price purchase request and then the buyer confirms the exact terms (B). Frozen product/unit/quantity/price/total and fulfilment terms (pickup or seller delivery, delivery charge never added to the total), two-sided self-reported completion, cancellation, confidential reports (Phase 27 owns moderation), audited contact exchange (address only for pickup), shop permissions `deal.view|respond`, platform admin read-only. Farmvest is not an escrow, payment processor or logistics provider: no payment, stock reservation, sale, invoice or delivery. Design: `44-PHASE-25-MARKETPLACE-DEALS.md`; contract: `docs/api/PHASE-25-MARKETPLACE-DEALS.md`. Phase 26 (monetisation) is not started.

## Post-V1 — Phase 26 (Marketplace monetisation)

Shop-scoped prepaid seller plans (Free = 10 published listings; Seller Plus / Pro configured by admins) and fixed-price promoted listings ("Sponsored", priority on page 1, cap 3), paid to Farmvest through Paystack with server-side verification, idempotent settlement and signed, de-duplicated webhooks. Feature flags off by default; expiry evaluated at read time; no commissions, escrow, wallets, payouts, buyer-seller payments or stock effects. Design: `45-PHASE-26-MARKETPLACE-MONETISATION.md`; contract: `docs/api/PHASE-26-MARKETPLACE-MONETISATION.md`.

## V1 launch status (Phase 21)

Phases 0–19 are committed through `f1e51e5`. Phase 21 hardening is implemented and locally verified; see [audit and acceptance record](../operations/PHASE-21-VERIFICATION.md) and [deployment runbook](../operations/LAUNCH.md) for final test evidence and remaining infrastructure gates. No unconditional production-readiness claim until restore, reconciliation, staging smoke and representative concurrent load gates are signed off. Phase 20 — Deferred from V1 / post-launch enhancement (WhatsApp integration, AI-assisted parsing); it is not a V1 launch blocker.
