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

## Architecture

### Layers

``` text
Clients
  Web SPA / future mobile client
          |
          v
Laravel JSON API
  Auth / Policies / Entitlements
  Application Services
  Domain Services
  Jobs / Notifications
          |
          v
MySQL
  Master data
  Operational events
  Ledgers / derived state
  Audit / billing
          |
          +--> Queue worker
          +--> Mail / Push
          +--> WhatsApp adapter (later)
          +--> AI parsing adapter (later)
          +--> Payment provider adapter
```

### Domain boundaries

1.  Identity & authentication
2.  Tenant/farm & membership
3.  Subscription/entitlements
4.  Master data & operation capabilities
5.  Measurements & conversions
6.  Locations/production areas
7.  Production cycles: livestock/fish batches and crop projects
8.  Operational records/event engine
9.  Population
10. Breeding/reproduction
11. Health/medicine
12. Inventory/feed/farm inputs
13. Work planning: templates, schedules, tasks, calendar
14. Production outputs/harvest
15. Purchasing/finance
16. Sales/invoices/payments/contacts
17. Dashboard/insights
18. Reports/exports
19. Notifications/audit/localization
20. Integrations: WhatsApp/AI
21. Platform administration

### Core invariants

-   Every farm-owned row carries `farm_id` directly or is reachable
    through an unambiguous farm-owned parent.
-   Tenant/farm scope is never accepted blindly from request body.
-   Monetary values use integer minor units or a fixed decimal strategy
    consistently; currency is explicit.
-   Quantity values store entered unit and normalized base quantity
    where conversion is possible.
-   Population and inventory mutations occur through movement/event
    records.
-   Reversal/adjustment is preferred to destructive history edits.
-   Closed cycles are protected from ordinary mutation.
-   Domain side effects run in DB transactions.
-   Jobs/webhooks use idempotency keys.
