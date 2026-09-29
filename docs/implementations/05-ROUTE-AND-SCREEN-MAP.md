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

## Proposed frontend route map

Public/auth: `/`, `/pricing`, `/login`, `/register`, `/verify-email`,
`/forgot-password`, `/reset-password`, `/onboarding/farm`.

App: `/dashboard`, `/work/tasks`, `/work/calendar`, `/work/templates`,
`/production/livestock`, `/production/livestock/:id`,
`/production/crops`, `/production/crops/:id`, `/production/breeding`,
`/production/breeding/:id`, `/records`, `/health`, `/inventory`,
`/inventory/feed`, `/inventory/medicine`, `/inventory/inputs`, `/sales`,
`/sales/:id`, `/invoices`, `/invoices/:id`, `/finance`, `/contacts`,
`/reports`, `/notifications`, `/settings`.

Settings children: `/settings/profile`, `/settings/farm`,
`/settings/operations`, `/settings/team`, `/settings/roles`,
`/settings/units`, `/settings/conversions`, `/settings/breeds`,
`/settings/egg-packaging`, `/settings/locations`,
`/settings/notifications`, `/settings/subscription`.

### Minimal navigation

Overview\
Work → Tasks, Calendar, Templates\
Production → Livestock, Crops, Breeding\
Records\
Health\
Inventory → Stock, Feed, Medicine, Farm Inputs\
Sales → Sales, Invoices, Payments\
Finance\
Contacts\
Reports\
Notifications\
Settings

Hide modules irrelevant to enabled farm operations or user permissions.
Do not create the reference app's long scrolling sidebar of rarely used
tools.

### Create/edit UX

Desktop: 480--560px right drawer for common create/edit flows.\
Mobile: full-height slide-over.\
Full pages are reserved for complex workflows/details/reports.

Global Quick Add: Start livestock batch; Start crop project; Start
breeding project; Record activity; Add expense; Record sale; Add stock;
Create task. Filter by role and enabled operations.
