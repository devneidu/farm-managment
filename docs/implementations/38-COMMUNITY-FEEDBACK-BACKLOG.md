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

## Feedback incorporated into roadmap

Observed/requested themes: - web version - actionable insights,
including rising mortality/outbreak-style warning ideas - growth
tracking reminders - nutrition/feed management - farm vet role/access -
vaccination/medication/treatment programs by stage - feeding/care
programs by stage - richer recurrence - pregnancy/heat-cycle tracking -
product image uploads if Farm Shop launches - birth status
(single/twin/triplet etc.) - EID reader connection - live
weighing/performance sessions - field maps and field treatment/cost
history - print invoices/reports and direct PDF sharing

Disposition: - Core now: web/responsive UX, deterministic farm-level
insights, growth reminders via templates, feed, vet permission preset,
health programs/templates, recurrence, pregnancy, birth outcome,
print/PDF reports/invoices. - Architecture-ready/later: outbreak
intelligence requiring reliable regional data, EID hardware, live
sessions, GIS field maps, public Farm Shop/community. - Rejected as core
differentiator: direct call button is not required; contacts may expose
phone actions on capable clients without making calling a marketplace
feature.
