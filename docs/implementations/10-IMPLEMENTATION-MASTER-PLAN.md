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

**Phase 20 --- Integrations**\
WhatsApp reports/alerts, AI draft parsing, provider abstractions.

**Phase 21 --- Performance, security, reconciliation and launch**\
Load/index review, security review, backups, observability, data
reconciliation, deployment runbook.

Do not combine phases merely to reduce file count. Each phase must
finish migrations/models/services/API/policies/tests before moving on.
