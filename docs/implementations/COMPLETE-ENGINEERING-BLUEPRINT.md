```{=html}
<!-- FILE: 00-READ-ME-FIRST.md -->
```
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

## How Claude Code should use this pack

1.  Read `00-READ-ME-FIRST.md`, `01-SYSTEM-ARCHITECTURE.md`,
    `02-DOMAIN-BEHAVIOUR-MATRIX.md`, `03-ERD.md`,
    `04-API-CONVENTIONS.md`, and the relevant phase file before coding.
2.  Implement **one phase at a time**. Do not jump ahead because a later
    table/API looks easy.
3.  Before changing a locked rule, stop and report the conflict.
4.  Backend authorization, tenant/farm scoping and entitlement checks
    are authoritative. Frontend hiding is UX only.
5.  Every write endpoint must validate ownership/scope, permissions and
    business invariants.
6.  Side effects touching population, stock or finance must be
    transactional and idempotent.
7.  Add automated tests in the same phase as the feature.
8.  Do not silently invent biological/agronomic defaults. Seed defaults
    as editable reference/configuration data and identify assumptions.
9.  Preserve `recorded_at` separately from `created_at`.
10. Never make task completion fabricate an operational quantity. Where
    evidence is required, task completion launches/links the proper
    record.

## Suggested repository docs

Copy this pack to `/docs/product/` or `/docs/implementation/` and add an
`AGENTS.md` instructing coding agents to follow the phase boundaries.

```{=html}
<!-- FILE: 01-SYSTEM-ARCHITECTURE.md -->
```
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

```{=html}
<!-- FILE: 02-DOMAIN-BEHAVIOUR-MATRIX.md -->
```
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

## Dynamic behaviour matrix

This is the "nothing gets forgotten" contract.

  --------------------------------------------------------------------------------------
  Context           Selection/capability                         UI / domain behaviour
  ----------------- -------------------------------------------- -----------------------
  Farm setup        Crops                                        Enable crop projects,
                                                                 crop records, crop
                                                                 reports; livestock-only
                                                                 UI hidden

  Farm setup        Poultry                                      Enable poultry batches,
                                                                 egg-capable
                                                                 species/config, poultry
                                                                 health/breeding
                                                                 templates

  Farm setup        Fishery                                      Enable fish batches,
                                                                 pond/location support,
                                                                 fish harvest/output

  Farm setup        Cattle/Goat/Sheep/Pig/Rabbit                 Enable livestock
                                                                 batches;
                                                                 pregnancy/birth
                                                                 workflow where
                                                                 configured

  Production        Livestock/Fish                               Initial population/head
                                                                 count required

  Production        Crop                                         Planting unit type +
                                                                 initial planting units
                                                                 required

  Crop              Seed/seedling/stem/tuber/sucker              Material type describes
                                                                 input; it does not
                                                                 replace planting-unit
                                                                 baseline

  Breeding          Incubation-capable species                   Eggs set/incubated,
                                                                 incubation start,
                                                                 expected hatch date,
                                                                 hatch status/outcome

  Breeding          Pregnancy-capable species                    breeding/service date,
                                                                 pregnancy
                                                                 status/checks, expected
                                                                 delivery, birth outcome

  Output            Egg-producing species                        Egg collection enabled;
                                                                 crate/tray +
                                                                 loose-piece compound
                                                                 entry supported

  Output            Milk-producing species                       Milk collection enabled

  Record            Feeding                                      feed item,
                                                                 quantity/unit, batch,
                                                                 date; reduce stock when
                                                                 linked

  Record            Mortality                                    batch, quantity, cause,
                                                                 date; reduce population

  Record            Weight                                       batch/animal,
                                                                 weight/unit, date;
                                                                 growth history

  Record            Water                                        amount/unit,
                                                                 batch/location, date

  Health            Vaccination/Medication/Deworming/Treatment   dynamic label; one or
                                                                 multiple medicines;
                                                                 per-medicine dose

  Medicine          packaged item                                packaging unit +
                                                                 primary unit +
                                                                 units/package

  Inventory         stock in                                     increase balance;
                                                                 optional
                                                                 supplier/expense

  Inventory         stock out/use                                decrease balance;
                                                                 reason/source

  Sale              livestock/output/produce                     decrease applicable
                                                                 inventory/population;
                                                                 financial consequence

  Crop harvest      crop output                                  increase produce
                                                                 inventory and project
                                                                 yield

  Work              schedule                                     generates future
                                                                 tasks/reminders; does
                                                                 not create fake
                                                                 operational records

  Work              template applied to cycle                    materializes
                                                                 recommended task
                                                                 schedule relative to
                                                                 cycle start/age

  Task              completion requiring evidence                opens/links relevant
                                                                 record form

  Reports           WhatsApp delivery enabled                    report generation
                                                                 remains canonical;
                                                                 WhatsApp only
                                                                 distributes a generated
                                                                 summary/file

  AI parser         natural-language record                      parse into draft only;
                                                                 user confirms before
                                                                 domain write
  --------------------------------------------------------------------------------------

## Species/operation capabilities

Do not hard-code animal names throughout the UI. Model capabilities such
as:

-   `supports_group_tracking`
-   `supports_individual_tracking`
-   `supports_incubation`
-   `supports_pregnancy`
-   `produces_eggs`
-   `produces_milk`
-   `supports_live_weight`
-   `supports_breeding`
-   `supports_harvest`
-   `supports_mortality`
-   `supports_feed_records`

Capabilities may be represented as normalized capability rows rather
than many database booleans.

## Poultry example

Chicken + incubation capability: - eggs incubated - start date -
configured/reference incubation period - calculated expected hatch
date - hatching status - actual hatched - notes

## Cattle/goat example

Pregnancy capability: - mother/parent source - sire/source where used -
service/breeding date - configured/reference gestation period -
calculated expected delivery - pregnancy status/checks - birth outcome -
offspring count - birth status where individually tracked
(single/twin/triplet/etc.)

## Crop baseline

A yam project may start with 50 heaps. If 47 establish, failed units = 3
and survival = 94%. Seed/tuber quantity, land area, planting units and
harvested output remain separate dimensions.

```{=html}
<!-- FILE: 03-ERD.md -->
```
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

## Logical ERD

``` mermaid
erDiagram
  USERS ||--o{ FARM_MEMBERSHIPS : belongs
  TENANTS ||--o{ FARMS : owns
  TENANTS ||--o{ FARM_MEMBERSHIPS : scopes
  FARMS ||--o{ FARM_MEMBERSHIPS : has
  FARMS ||--o{ FARM_OPERATIONS : enables
  OPERATION_TYPES ||--o{ FARM_OPERATIONS : selected
  OPERATION_TYPES ||--o{ SPECIES : groups
  SPECIES ||--o{ SPECIES_CAPABILITIES : has
  CAPABILITIES ||--o{ SPECIES_CAPABILITIES : defines
  SPECIES ||--o{ BREEDS : has
  FARMS ||--o{ CUSTOM_BREEDS : defines

  FARMS ||--o{ LOCATIONS : has
  LOCATIONS ||--o{ PRODUCTION_AREAS : has
  FARMS ||--o{ PRODUCTION_CYCLES : has
  PRODUCTION_AREAS ||--o{ PRODUCTION_CYCLES : hosts
  SPECIES ||--o{ PRODUCTION_CYCLES : livestock
  CROP_TYPES ||--o{ PRODUCTION_CYCLES : crop

  PRODUCTION_CYCLES ||--o{ POPULATION_MOVEMENTS : changes
  PRODUCTION_CYCLES ||--o{ OPERATIONAL_RECORDS : records
  RECORD_TYPES ||--o{ OPERATIONAL_RECORDS : typed

  FARMS ||--o{ INVENTORY_ITEMS : owns
  INVENTORY_ITEMS ||--o{ INVENTORY_MOVEMENTS : moves
  STORAGE_LOCATIONS ||--o{ INVENTORY_MOVEMENTS : stored
  OPERATIONAL_RECORDS ||--o{ INVENTORY_MOVEMENTS : causes

  FARMS ||--o{ BREEDING_PROJECTS : has
  BREEDING_PROJECTS ||--o{ BREEDING_MILESTONES : schedules
  BREEDING_PROJECTS ||--o{ BREEDING_OUTCOMES : results

  FARMS ||--o{ HEALTH_RECORDS : has
  HEALTH_RECORDS ||--o{ HEALTH_RECORD_MEDICINES : uses
  INVENTORY_ITEMS ||--o{ HEALTH_RECORD_MEDICINES : medicine

  FARMS ||--o{ WORK_TEMPLATES : owns
  WORK_TEMPLATES ||--o{ WORK_TEMPLATE_ITEMS : contains
  FARMS ||--o{ SCHEDULES : has
  SCHEDULES ||--o{ TASKS : generates
  TASKS }o--o| OPERATIONAL_RECORDS : completion_evidence

  FARMS ||--o{ CONTACTS : has
  FARMS ||--o{ SALES : has
  SALES ||--o{ SALE_ITEMS : contains
  SALES ||--o{ INVOICES : may_generate
  INVOICES ||--o{ PAYMENTS : receives

  FARMS ||--o{ FINANCE_TRANSACTIONS : books
  OPERATIONAL_RECORDS ||--o{ FINANCE_TRANSACTIONS : causes
  SALES ||--o{ FINANCE_TRANSACTIONS : causes

  TENANTS ||--o| SUBSCRIPTIONS : has
  PLANS ||--o{ PLAN_PRICES : prices
  PLANS ||--o{ PLAN_ENTITLEMENTS : grants
  ENTITLEMENTS ||--o{ PLAN_ENTITLEMENTS : defines

  USERS ||--o{ AUDIT_LOGS : acts
  FARMS ||--o{ NOTIFICATIONS : receives
```

## Recommended table inventory

Identity: `users`, `email_verification_otps`, `social_accounts`,
`personal_access_tokens`.

Tenancy/RBAC: `tenants`, `farms`, `farm_memberships`, `roles`,
`permissions`, `role_permissions`, `membership_roles`,
`farm_invitations`.

Operations/master data: `operation_types`, `farm_operations`, `species`,
`breeds`, `custom_breeds`, `crop_types`, `capabilities`,
`species_capabilities`, `record_types`, `record_type_fields`,
`reference_values`.

Measurements: `measurement_dimensions`, `units`, `unit_conversions`,
`farm_unit_preferences`, `package_conversions`.

Locations: `locations`, `production_areas`, `storage_locations`.

Production: `production_cycles`, `crop_project_details`,
`livestock_batch_details`, optional later `animals`,
`animal_identifiers`.

Events/population: `operational_records`, `record_values` or typed
detail tables, `record_attachments`, `population_movements`.

Breeding: `breeding_projects`, `breeding_parents`,
`breeding_milestones`, `breeding_checks`, `breeding_outcomes`,
`offspring_links`.

Health: `health_records`, `health_record_medicines`.

Inventory: `inventory_items`, `inventory_lots`, `inventory_movements`,
`feed_formulas`, `feed_formula_items`.

Work: `work_templates`, `work_template_items`, `schedules`, `tasks`,
`task_reminders`, `task_completions`.

Commerce/finance: `contacts`, `purchases`, `purchase_items`, `sales`,
`sale_items`, `invoices`, `invoice_items`, `payments`,
`finance_categories`, `finance_transactions`.

Reporting: derived queries/materialized summaries as needed;
`report_exports`.

Platform: `notifications`, `audit_logs`, `platform_settings`,
`feature_flags`, localization tables if DB-managed.

Billing: `plans`, `plan_prices`, `entitlements`, `plan_entitlements`,
`subscriptions`, `billing_transactions`, `subscription_events`.

```{=html}
<!-- FILE: 04-API-CONVENTIONS.md -->
```
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

## API conventions

Base: `/api/v1`

JSON envelope:

``` json
{"data": {}, "meta": {}, "message": null}
```

Validation: HTTP 422 with field errors.\
Unauthenticated: 401. Forbidden: 403. Missing: 404. Conflict/invariant:
409 where appropriate.\
Use pagination for collections. Filters use query params. Dates are
ISO-8601. API stores UTC timestamps; farm timezone controls
presentation/scheduling.

### Authentication and onboarding

-   `POST /auth/register`
-   `POST /auth/email/otp/resend`
-   `POST /auth/email/otp/verify`
-   `POST /auth/login`
-   `POST /auth/google`
-   `POST /auth/forgot-password`
-   `POST /auth/reset-password`
-   `POST /auth/logout`
-   `GET /me`
-   `GET /onboarding/status`
-   `POST /onboarding/farm`
-   `GET /master/farm-operations`

Until farm setup is complete, only auth/onboarding/master endpoints
required for setup are allowed.

### Farm/settings/team

-   `GET /farm`
-   `PATCH /farm`
-   `GET|PUT /farm/operations`
-   `GET /farm/settings`
-   `PATCH /farm/settings`
-   `GET /farm/members`
-   `POST /farm/invitations`
-   `PATCH /farm/members/{member}`
-   `DELETE /farm/members/{member}`
-   `GET /roles`
-   `GET /permissions`

### Master data/config

-   `GET /master/species?operation=`
-   `GET /master/species/{species}/capabilities`
-   `GET /master/species/{species}/breeds`
-   `GET|POST /custom-breeds`
-   `PATCH|DELETE /custom-breeds/{breed}`
-   `GET /master/crops`
-   `GET /master/record-types`
-   `GET /master/task-categories`
-   `GET /master/units`
-   `GET|PUT /settings/units`
-   `GET|PUT /settings/package-conversions`

### Locations

-   `GET|POST /locations`
-   `GET|PATCH|DELETE /locations/{location}`
-   `GET|POST /production-areas`
-   `GET|PATCH|DELETE /production-areas/{area}`
-   `GET|POST /storage-locations`

### Production cycles

-   `GET|POST /production-cycles`
-   `GET|PATCH /production-cycles/{cycle}`
-   `POST /production-cycles/{cycle}/close`
-   `POST /production-cycles/{cycle}/reopen` (permission controlled)
-   `GET /production-cycles/{cycle}/summary`
-   `GET /production-cycles/{cycle}/activity`
-   Convenience aliases may exist: `/livestock-batches`,
    `/crop-projects`, but one canonical service/domain should own
    writes.

### Records

-   `GET|POST /records`
-   `GET /records/{record}`
-   `POST /records/{record}/reverse`
-   `POST /records/{record}/attachments`
-   `GET /record-types/{type}/schema`
-   Quick actions still submit to the canonical records endpoint.

### Breeding

-   `GET|POST /breeding-projects`
-   `GET|PATCH /breeding-projects/{project}`
-   `POST /breeding-projects/{project}/checks`
-   `POST /breeding-projects/{project}/outcomes`
-   `GET /breeding-projects/{project}/milestones`

### Health/medicine

-   `GET|POST /health-records`
-   `GET /health-records/{record}`
-   `GET|POST /inventory/medicines`
-   `PATCH /inventory/medicines/{medicine}`

### Inventory/feed

-   `GET|POST /inventory/items`
-   `GET /inventory/items/{item}`
-   `GET /inventory/items/{item}/movements`
-   `POST /inventory/stock-in`
-   `POST /inventory/stock-out`
-   `POST /inventory/adjustments`
-   `GET|POST /feed-formulas`
-   `GET|PATCH /feed-formulas/{formula}`

### Work planning

-   `GET|POST /work-templates`
-   `GET|PATCH|DELETE /work-templates/{template}`
-   `POST /work-templates/{template}/items`
-   `POST /production-cycles/{cycle}/apply-template`
-   `GET|POST /schedules`
-   `GET|PATCH|DELETE /schedules/{schedule}`
-   `GET|POST /tasks`
-   `GET|PATCH /tasks/{task}`
-   `POST /tasks/{task}/complete`
-   `POST /tasks/{task}/cancel`
-   `GET /calendar?from=&to=`

### Contacts/sales/invoices/payments

-   `GET|POST /contacts`
-   `GET|PATCH /contacts/{contact}`
-   `GET|POST /sales`
-   `GET /sales/{sale}`
-   `POST /sales/{sale}/invoice`
-   `GET|POST /invoices`
-   `GET /invoices/{invoice}`
-   `GET /invoices/{invoice}/pdf`
-   `POST /invoices/{invoice}/send`
-   `POST /invoices/{invoice}/payments`
-   `GET /payments`

### Finance

-   `GET|POST /finance/transactions`
-   `GET /finance/summary`
-   `GET /finance/categories`
-   `POST /expenses`
-   `POST /income`

### Dashboard/reports

-   `GET /dashboard`
-   `GET /dashboard/calendar`
-   `GET /insights`
-   `GET /reports`
-   `POST /reports/exports`
-   `GET /reports/exports/{export}`
-   `GET /reports/exports/{export}/download`

### Notifications

-   `GET /notifications`
-   `POST /notifications/{notification}/read`
-   `POST /notifications/read-all`
-   `GET|PATCH /notification-preferences`

### Subscription/billing

-   `GET /public/plans`
-   `GET /subscription`
-   `GET /subscription/entitlements`
-   `GET /subscription/usage`
-   `POST /subscription/checkout`
-   `POST /subscription/cancel`
-   `POST /subscription/resume`
-   `POST /billing/webhooks/{provider}`

### Platform admin

Prefix `/platform-admin` with platform-admin authorization:
plans/prices/entitlements, operation/species/crop reference data,
task-template defaults, settings, feature flags, audits and support
tools.

### Future integrations

-   `POST /integrations/whatsapp/report-deliveries`
-   `GET|PATCH /integrations/whatsapp/settings`
-   `POST /ai/parse-record` → returns draft only
-   `POST /ai/parse-task` → returns draft only
-   `POST /ai/confirm-draft/{draft}` → canonical validated write after
    user confirmation

```{=html}
<!-- FILE: 05-ROUTE-AND-SCREEN-MAP.md -->
```
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

```{=html}
<!-- FILE: 06-DATA-MEASUREMENTS-AND-CONVERSIONS.md -->
```
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

## Measurement model

Dimensions: count, weight, volume, area, length, temperature, time,
currency and configured package.

Examples: - Count: head, bird, piece, egg, planting unit - Weight: g,
kg, tonne - Volume: ml, L - Area: m², hectare, acre - Packages: bag,
sack, crate, tray, bottle, carton --- configurable conversion, never
universally assumed

Store: - entered quantity - entered unit - normalized quantity -
normalized/base unit - conversion snapshot used at record time

### Compound quantity

Eggs: if farm config says 1 crate = 30 eggs: 3 crates + 14 pieces = 104
pieces normalized. Preserve 3 crates + 14 pieces for display/audit.

Crop output: 12 bags + 18kg can normalize only if that product/farm has
a bag→kg conversion.

### Crop measurements

Never conflate: - land area - planting units (holes/heaps/stands) -
material consumed (kg seed, number of seedlings/tubers if measured) -
surviving/established units - harvested output

```{=html}
<!-- FILE: 07-WORK-TEMPLATES-SCHEDULES-TASKS.md -->
```
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

## Simplified planning model

The reference screenshots expose Schedules, Tasks and Automatic Records.
Our product should simplify this into:

1.  **Work Templates** --- reusable recommended/custom plans.
2.  **Schedules** --- recurring rules that generate tasks.
3.  **Tasks** --- work someone needs to do.
4.  **Calendar** --- unified view.
5.  **Operational Records** --- what actually happened.

Do not create recurring mortality/income/medicine records automatically
merely because a date arrived.

## Template engine

Platform can seed editable templates by operation/species/crop/stage.
Farms can clone and customize templates.

Example broiler template: - Day 0: placement/setup checks - Day 1--3:
configured early-care tasks - Daily 06:00: feeding - Daily 10:00:
water/check - stage-based weighing/vaccination reminders as configured

These are **configurable template data**, not medical/veterinary
guarantees.

Template fields: name, scope operation, optional species/crop, optional
breed/stage, description, version, source (`platform`/`farm`), active.

Template item: title, category, offset from cycle start or recurrence
rule, time, reminder offsets, assignee role/user optional, linked record
type optional, instructions, required evidence flag.

When a cycle is created: - recommend applicable templates - user may
Apply, Customize, or Skip - applying materializes schedules/tasks
against that cycle - later platform template edits do not silently
rewrite already-applied farm schedules

Task categories include feeding/watering, egg collection,
vaccination/medication, breeding/reproduction, growth monitoring, record
keeping, maintenance/repair, cleaning/sanitation, movement/rotation,
procurement/orders, irrigation, crop care, harvest, payment and other.

Recurring options: one-time, daily, selected weekdays, weekly, every N
weeks, monthly, every N months, quarterly, yearly, custom end
date/occurrence count.

Growth tracking can support age-relative milestones (e.g. 4, 8, 16 weeks
or 1,2,3,6 months) through templates.

Task completion: - simple work can be marked complete directly - tasks
requiring a farm record launch the appropriate form with
cycle/date/context prefilled - successful record links back to and
completes the task

```{=html}
<!-- FILE: 08-REPORTING-WHATSAPP-AI-LOCALIZATION.md -->
```
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

## Reporting

Report families: - production/cycle performance - livestock
population/mortality/growth - egg/milk/output - crop establishment,
activities, loss, harvest/yield - breeding outcomes - health/treatment -
inventory/feed/input consumption - sales/invoices/payments -
income/expenses/profitability - task completion/compliance -
contacts/customer/supplier history - audit/activity

Exports: CSV/XLSX/PDF where appropriate.

## WhatsApp --- phased enhancement

WhatsApp is not the source of truth. The reporting service generates the
report first, then a WhatsApp delivery adapter distributes a
summary/link/PDF where provider capabilities permit.

Initial useful flows: - owner receives daily/weekly farm summary -
critical alert: mortality threshold, low stock, overdue critical task -
manually share invoice/report - scheduled management report

Keep provider credentials encrypted and webhooks verified/idempotent.

## AI parsing --- later

Examples: "Used 2 bags of starter feed for Broiler Batch A this
morning." "Sold 5 crates of eggs to Emeka for ₦28,000." "3 birds died in
House 2."

Pipeline: text → parser → structured **draft** → validation → user
confirmation → canonical domain service.

Never let an LLM directly mutate inventory/population/finance without
confirmation and server-side validation.

## Nigerian localization

Architecture should support locale keys from day one. Launch language is
English. Nigerian-language packs can be added progressively (for example
Hausa, Yoruba, Igbo and Nigerian Pidgin) after terminology review by
native speakers. Farm data itself is not machine-translated by default.
Currency defaults to NGN.

```{=html}
<!-- FILE: 09-SECURITY-AUDIT-QUALITY.md -->
```
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

## Security

-   Sanctum/session/token strategy appropriate to chosen clients.
-   Rate-limit auth/OTP, exports, AI parsing and webhooks.
-   OTP expiry, resend throttling, attempt limits and one-time use.
-   Server-side farm scoping and policies on every resource.
-   Secure file upload validation and private storage.
-   Never store card details.
-   Encrypt provider secrets and sensitive integration credentials.
-   Audit privileged/configuration changes.
-   Prevent mass assignment of farm/tenant ownership.
-   Use signed/authorized download URLs for private exports.
-   Background jobs re-check scope/authorization context where needed.

## Audit

Audit actor, farm, action, resource type/id, before/after safe snapshot,
reason, request/correlation id, timestamp. Avoid storing secrets in
audit payloads.

## Reliability

-   transaction boundaries around multi-ledger effects
-   idempotency key on side-effecting integration/payment operations
-   row locks or atomic balance strategy for inventory/population
    contention
-   queue retries with safe dedupe
-   reconciliation commands/jobs for ledgers and billing
-   soft-delete only where historical references permit; prefer
    status/closure for accounting records

## Test pyramid

Feature/API tests for every phase, domain/service tests for calculations
and side effects, policy tests for RBAC/scoping, integration tests for
payments/WhatsApp adapters, and a small E2E smoke suite for signup→farm
setup→cycle→record→dashboard.

```{=html}
<!-- FILE: 10-IMPLEMENTATION-MASTER-PLAN.md -->
```
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

```{=html}
<!-- FILE: 11-PHASE-00-FOUNDATION.md -->
```
# Phase 0 --- Foundation

## Objective

Create a clean API-first Laravel foundation that later domain phases can
safely build on.

## Backend scope

Configure environment strategy, MySQL, queues, mail, private/public
storage, API versioning, exception JSON, request IDs, UTC persistence,
Africa/Lagos presentation defaults, factories, seeders, test DB, CI and
static/code-quality tooling. Establish application/domain service
conventions and transaction helpers.

## API/UI contract

`/api/v1/health` plus standard error/validation envelope. No farm domain
UI yet.

## Critical business rules

No business feature should be implemented here.
IDs/timestamps/money/quantity conventions must be documented.

## Tests / acceptance

Health endpoint; CI boots app; test DB works; queue/storage/mail fakes
work; API validation/error shapes tested.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 12-PHASE-01-AUTH-ONBOARDING.md -->
```
# Phase 1 --- Auth, tenancy and onboarding

## Objective

Implement the exact simple signup and mandatory farm setup flow.

## Backend scope

`users`, OTPs, social accounts, hidden tenant, farm, initial owner
membership. Email signup requires phone/password confirmation and email
OTP. Google skips app OTP. Create tenant safely; after authentication
expose `requires_farm_setup`. Farm setup creates Farm Name + selected
Farm Operations and defaults Nigeria/NGN/Africa-Lagos/English.

## API/UI contract

Use auth/onboarding endpoints from API contract. Frontend routes
`/register`, `/verify-email`, `/login`, `/onboarding/farm`.

## Critical business rules

Farm setup cannot be dismissed/bypassed by URL or API. Crop-only,
livestock-only and mixed farms valid. No
organization/country/currency/timezone/logo/address questions in first
setup.

## Tests / acceptance

Duplicate email; expired/wrong OTP; resend limits; Google first login;
onboarding gate; at least one operation; tenant isolation.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 13-PHASE-02-RBAC-SETTINGS.md -->
```
# Phase 2 --- RBAC, team and minimal settings

## Objective

Give farms safe collaboration without a complicated settings area.

## Backend scope

Memberships, invitations, roles/permissions, farm profile, enabled
operations, notification preferences shell, locations/config entry
points.

## API/UI contract

`/farm`, `/farm/members`, `/farm/invitations`, `/roles`, `/permissions`,
`/settings/*`.

## Critical business rules

Roles: Farm Admin/Owner, Manager, Finance, Farm Worker; optional Vet
role may be implemented as a role preset granting health/medicine
permissions rather than hard-coded special-case logic. Backend policies
authoritative.

## Tests / acceptance

Invite/accept/revoke; cross-farm denial; worker cannot access finance;
vet preset limited appropriately; owner safety.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 14-PHASE-03-SUBSCRIPTIONS.md -->
```
# Phase 3 --- Subscription and entitlements

## Objective

Create a configurable commercial gate without coupling farm logic to
plan names.

## Backend scope

Plans, prices, entitlements, plan entitlements, subscription, billing
transactions/events, entitlement service and usage resolvers. Provider
abstraction; payment integration may be completed later if provider not
yet chosen.

## API/UI contract

`/public/plans`, `/subscription`, `/subscription/entitlements`,
`/subscription/usage`, checkout/cancel/resume.

## Critical business rules

No `if plan == pro`. Downgrades do not delete historical farm data.
Numeric limits must be concurrency-safe.

## Tests / acceptance

Boolean gate, numeric limit, upgrade unlock, downgrade over-limit,
tenant isolation, duplicate webhook idempotency.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 15-PHASE-04-MASTER-DATA.md -->
```
# Phase 4 --- Operations and capability metadata

## Objective

Make dynamic forms/configuration data-driven.

## Backend scope

Operation types, species, breeds, custom breeds, crop types,
capabilities, species capabilities, record types/schema metadata,
reference lists. Seed only reviewed defaults.

## API/UI contract

`/master/farm-operations`, species/capabilities/breeds, crops,
record-types/schema, custom breeds.

## Critical business rules

Changing species can change visible fields/statuses via returned
schema/capabilities. Do not encode biological rules only in Vue
components.

## Tests / acceptance

Capability responses; custom breed farm scope; disabled operation
filtering; schema versioning compatibility.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 16-PHASE-05-MEASUREMENTS.md -->
```
# Phase 5 --- Measurements and conversions

## Objective

Implement trustworthy units and compound quantities before
inventory/records.

## Backend scope

Dimensions, units, farm preferences, package conversions, normalization
service and conversion snapshots.

## API/UI contract

`/master/units`, `/settings/units`, `/settings/package-conversions`.

## Critical business rules

Never assume bag/crate size globally. Preserve entered representation
and normalized quantity.

## Tests / acceptance

3 crates +14 with 30/crate =104; incompatible dimension rejected;
historical conversion snapshot unaffected by later config edit.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 17-PHASE-06-LOCATIONS.md -->
```
# Phase 6 --- Locations and production areas

## Objective

Represent farm physical context simply.

## Backend scope

Locations, production areas typed as house/pen/pond/field/plot/etc,
storage locations.

## API/UI contract

CRUD routes in API contract.

## Critical business rules

Names unique where appropriate within farm; archived areas remain
referenceable by history.

## Tests / acceptance

CRUD, archive with history, cross-farm denial.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 18-PHASE-07-PRODUCTION-CYCLES.md -->
```
# Phase 7 --- Livestock batches and crop projects

## Objective

Create the production backbone.

## Backend scope

Common production cycle plus livestock/fish and crop detail. Livestock
initial population required. Crop planting-unit type and initial
planting units required; planting material type required; area and
material quantity separate.

## API/UI contract

`/production-cycles` and detail/summary/activity endpoints; UI uses
Livestock Batch or Crop Project wording.

## Critical business rules

Do not infer seed count from holes/heaps. Cycle closure locks ordinary
writes after reconciliation.

## Tests / acceptance

Crop-only/livestock/mixed; initial population movement; crop 50 heaps;
close/reopen permissions.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 19-PHASE-08-RECORDS-POPULATION.md -->
```
# Phase 8 --- Operational records and population

## Objective

Capture real-world farm events and derive population safely.

## Backend scope

Canonical operational record service, type-specific validators/detail
payloads, attachments, population movements. Implement feed use, egg
collection, milk, mortality, weight, temperature, water and core crop
activity shells.

## API/UI contract

`GET|POST /records`, schema endpoint, reverse endpoint, attachments.

## Critical business rules

Mortality reduces population; egg collection increases output stock only
when inventory integration is enabled; records have `recorded_at`;
corrections use reversal/adjustment.

## Tests / acceptance

Dynamic validation; insufficient population; idempotent side effects;
attachment policy; reversal restores derived effects.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 20-PHASE-09-INVENTORY.md -->
```
# Phase 9 --- Inventory, feed and farm inputs

## Objective

Make all stock explainable from movements.

## Backend scope

Inventory items/lots/stores/movements, stock in/out/adjustments, feed
formulas distinct from feed stock, low-stock thresholds.

## API/UI contract

Inventory and feed endpoints.

## Critical business rules

No unexplained editable balance. Feed use can consume stock
transactionally. Farm inputs and medicine share movement infrastructure
but retain domain metadata.

## Tests / acceptance

Concurrent stock out; negative-stock policy; transfer;
purchase/use/adjustment; feed formula does not itself equal stock.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 21-PHASE-10-HEALTH.md -->
```
# Phase 10 --- Health and medicine

## Objective

Support livestock health and medicine inventory without pretending to
diagnose.

## Backend scope

Vaccination, medication, deworming, treatment, disease/issue, vet
visit/follow-up. Multiple medicines per health record with dose.
Medicine packaging/primary unit conversion and withdrawal metadata.

## API/UI contract

`/health-records`, medicine inventory endpoints.

## Critical business rules

Health types drive labels/fields. Medicine use reduces linked stock.
Follow-up may create task. Crop health can use crop-specific
issue/treatment records.

## Tests / acceptance

Multi-medicine doses; stock effects; withdrawal date; farm-vet
permission preset; no cross-farm medicine.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 22-PHASE-11-BREEDING.md -->
```
# Phase 11 --- Breeding and reproduction

## Objective

Implement capability-driven incubation and pregnancy workflows.

## Backend scope

Breeding projects, parent links, milestones/checks/outcomes, offspring
links. Expected dates from configured/reference periods. Poultry
incubation and pregnancy-based workflows differ.

## API/UI contract

Breeding endpoints.

## Critical business rules

Expected dates are estimates. Changing start date recalculates expected
date until outcome/lock. Successful outcomes may create/link offspring
batch/animals through explicit confirmation.

## Tests / acceptance

Incubation fields appear only when capability applies; pregnancy fields
only where applicable; milestones; actual vs expected; outcome side
effects.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 23-PHASE-12-WORK.md -->
```
# Phase 12 --- Templates, schedules, tasks and calendar

## Objective

Turn farm knowledge into simple actionable work without automatic fake
records.

## Backend scope

Platform/farm work templates, items, schedules, generated tasks,
reminders, assignments, calendar aggregation, completion evidence links.

## API/UI contract

Work-template, schedule, task and calendar endpoints.

## Critical business rules

Recommend template when new cycle is created. User can
apply/customize/skip. Schedules create tasks; tasks never silently
create mortality/income/medicine quantities.

## Tests / acceptance

Template versioning; recurring generation dedupe; overdue/due-today
states; evidence-linked completion; role assignment.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 24-PHASE-13-CROPS-OUTPUTS.md -->
```
# Phase 13 --- Crop operations and outputs

## Objective

Complete crop-specific workflows and unified production outputs.

## Backend scope

Land prep, planting, establishment/survival, fertilizer,
pesticide/herbicide, irrigation, weeding, growth stage, crop loss, crop
harvest; output inventory integration.

## API/UI contract

Record engine schemas plus crop project detail endpoints.

## Critical business rules

Planting units are baseline. Harvest quantity may be compound if
configured. Crop harvest increases produce inventory; livestock exit is
not called crop harvest.

## Tests / acceptance

50→47 survival 94%; loss; harvest stock-in; unit validation;
plot/project scope.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 25-PHASE-14-FINANCE-PURCHASING.md -->
```
# Phase 14 --- Contacts, purchasing and finance

## Objective

Connect operational costs to simple farm bookkeeping.

## Backend scope

Contacts, suppliers/customers, purchases, purchase items, income/expense
categories, finance transactions and optional operational/cycle
allocation.

## API/UI contract

Contacts, purchases, finance, expenses/income endpoints.

## Critical business rules

Operational actions may offer `Record as expense/income`; use one
canonical finance transaction and source link to avoid duplicates.

## Tests / acceptance

Purchase stock + expense transaction; duplicate prevention; cycle
profitability allocation; finance role permissions.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 26-PHASE-15-SALES-INVOICES.md -->
```
# Phase 15 --- Sales, invoices and payments

## Objective

Implement the selling flow while keeping sale, invoice and payment
distinct.

## Backend scope

Sales/items, inventory/population effects, invoices/items, PDF
generation, payments, balances/status.

## API/UI contract

Sales/invoice/payment endpoints.

## Critical business rules

Sale is operational/commercial event. Invoice is customer document.
Payment settles invoice/receivable. `Save & Create Invoice` is a
convenience workflow, not domain conflation.

## Tests / acceptance

Livestock sale reduces population; egg/produce sale reduces stock;
partial payment; invoice PDF; cancellation/reversal policy.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 27-PHASE-16-DASHBOARD.md -->
```
# Phase 16 --- Dashboard and insights

## Objective

Build the simple command-center dashboard represented by the approved
design direction.

## Backend scope

Dashboard aggregation service, calendar summary, deterministic insight
rules, recent activity.

## API/UI contract

`GET /dashboard`, `/dashboard/calendar`, `/insights`.

## Critical business rules

Only relevant KPIs. Crop-only farm sees crop metrics, not eggs. Worker
sees tasks/operations, not unrestricted profit. Quick Add filters by
role/operations.

## Tests / acceptance

Role variants, empty state, mixed farm, overdue tasks, low stock,
mortality threshold insight, query performance.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 28-PHASE-17-REPORTS-NOTIFICATIONS.md -->
```
# Phase 17 --- Reports, exports, notifications and audit

## Objective

Make farm history usable and shareable.

## Backend scope

Report queries, queued exports, PDF/XLSX/CSV, notification
center/preferences, audit views.

## API/UI contract

Reports/exports/notifications endpoints.

## Critical business rules

Exports are farm-scoped and private. Report calculations reconcile to
authoritative ledgers/events.

## Tests / acceptance

Export permissions; large queued export; report totals; audit
visibility; notification read states.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 29-PHASE-18-ADMIN.md -->
```
# Phase 18 --- Platform administration

## Objective

Allow safe management of configuration without deployments.

## Backend scope

Platform admin for plans/entitlements, operation/species/crop master
data, capability schemas, platform work templates, settings, flags and
audit/support.

## API/UI contract

`/platform-admin/*`.

## Critical business rules

Platform admin is distinct from Farm Admin. Dangerous config edits need
validation/versioning and audit.

## Tests / acceptance

Authorization, audit, template publication, schema compatibility, plan
changes.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 30-PHASE-19-LOCALIZATION.md -->
```
# Phase 19 --- Localization/accessibility

## Objective

Prepare the product for Nigerian language support and inclusive use.

## Backend scope

Translation-key infrastructure, locale preference, translated UI
resources, accessibility fixes.

## API/UI contract

`PATCH /me/preferences` or equivalent locale preference endpoint.

## Critical business rules

English launch; Hausa/Yoruba/Igbo/Pidgin packs only after terminology
review. User-entered farm records are not silently translated.

## Tests / acceptance

Fallback locale; missing key; mobile keyboard/input; accessibility
labels/focus/contrast.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 31-PHASE-20-INTEGRATIONS.md -->
```
# Phase 20 --- WhatsApp and AI parsing

## Objective

Add high-value integrations after canonical farm workflows are stable.

## Backend scope

WhatsApp adapter/settings/report delivery, verified webhook if inbound
is later enabled; AI parser producing typed drafts; confirmation
workflow.

## API/UI contract

Integration endpoints in API contract.

## Critical business rules

WhatsApp distributes canonical reports/alerts. AI never writes
authoritative records directly. Confirmation invokes same domain
services as normal UI.

## Tests / acceptance

Provider failure/retry; duplicate webhook; parser ambiguity;
confirmation; permission and entitlement gates.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 32-PHASE-21-LAUNCH.md -->
```
# Phase 21 --- Launch hardening

## Objective

Prepare production safely.

## Backend scope

Indexes, caching, queue monitoring, logs/metrics, backups, restore test,
rate limits, security headers, data reconciliation commands,
deployment/rollback runbook.

## API/UI contract

Operational/admin endpoints only where needed.

## Critical business rules

No launch with unreconciled population/stock/finance or untested backup
restore.

## Tests / acceptance

Load tests for dashboard/lists; security review; backup restore; queue
retry; reconciliation; smoke E2E.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

```{=html}
<!-- FILE: 33-SIDE-EFFECT-MATRIX.md -->
```
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

## Automatic side effects

  ---------------------------------------------------------------------
  User action                        Required system effects
  ---------------------------------- ----------------------------------
  Egg collection                     normalize quantity; production
                                     history; inventory increase where
                                     enabled; analytics

  Mortality                          population decrease; mortality
                                     metrics; alert evaluation; audit

  Feeding                            feed stock decrease when linked;
                                     consumption history; cost
                                     allocation if configured

  Crop harvest                       produce inventory increase;
                                     yield/progress update

  Livestock sale/exit                population decrease; sale/finance
                                     link

  Produce/output sale                inventory decrease; sale/finance
                                     link

  Purchase/stock-in                  inventory increase; optional
                                     expense/payable

  Medicine administration            health history; medicine stock
                                     decrease; withdrawal/follow-up if
                                     configured

  Start breeding                     expected date/milestones

  Successful breeding outcome        outcome metrics; explicit
                                     offspring registration/population
                                     effect

  Complete evidence task             linked operational record; task
                                     completion

  Close cycle                        reconcile; validate; protect
                                     ordinary edits

  Reverse record                     compensating movements; audit; no
                                     history deletion
  ---------------------------------------------------------------------

All multi-effect actions are transactional.

```{=html}
<!-- FILE: 34-PERMISSIONS-MATRIX.md -->
```
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

## Role presets

Permissions are granular; roles are presets.

  ------------------------------------------------------------------------------------------------
  Area                Owner/Admin        Manager        Finance       Farm Worker       Vet preset
  ----------------- ------------- -------------- -------------- ----------------- ----------------
  Farm config/team           Full        Limited             No                No               No

  Production cycles          Full           Full           Read     Read/assigned             Read

  Operational                Full           Full           Read            Create   Health-related
  records                                                         assigned/common             read

  Health/medicine            Full           Full   Read cost as          Assigned    Create/manage
                                                        allowed                             health

  Inventory                  Full           Full Financial read      Assigned use         Medicine
                                                                                          relevant

  Tasks/calendar             Full           Full   Own/relevant      Own/assigned       Own/health

  Finance                    Full   Configurable           Full                No        No unless
                                                                                           granted

  Sales/invoices             Full   Configurable           Full        No/default               No
                                                                          limited 

  Reports                    Full    Operational      Financial    Assigned/basic           Health

  Settings                   Full        Limited             No                No               No
  ------------------------------------------------------------------------------------------------

Every permission remains backend enforced and can be refined without
changing role names.

```{=html}
<!-- FILE: 35-DASHBOARD-UI-CONTRACT.md -->
```
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

## Dashboard UI contract

Desktop: - compact farm context/header - priority/alert strip -
contextual KPI row - Today & Overdue - calendar - Quick Record - active
production - deterministic insights - recent activity - Quick Add

Mobile: - compact header - priority card - horizontally scrollable KPI
cards - top 3 Today items - 2x3 Quick Record grid - active production -
compact 7-day calendar - 1--2 insights - bottom nav: Home, Work, center
Quick Add, Records, More

Design principles learned from reference: - important features front and
center - rarely used configuration behind Settings/secondary
navigation - small intuitive icons - minimal forms and progressive
disclosure - dynamic fields appear only when relevant

```{=html}
<!-- FILE: 36-SETTINGS-MINIMAL-SCOPE.md -->
```
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

## Minimal Settings

Account: - Profile - Security

Farm: - Farm details - Farm operations - Team & access - Locations

Preferences: - Language - Units & measurements - Notifications

Production configuration: - Custom breeds - Package/conversion settings
(including egg crate/tray sizes where relevant) - Work templates

Billing: - Subscription & billing - Export data

Do not expose affiliate/referral/community configuration in core
Settings unless that feature is actually launched. Keep advanced
platform reference data in Platform Admin, not farmer settings.

```{=html}
<!-- FILE: 37-OPEN-DECISIONS-AND-NONBLOCKERS.md -->
```
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

## Decisions intentionally not hard-coded

These must remain configurable or be finalized before their dependent
phase, but they do not block earlier phases:

-   final paid-plan prices and numerical limits
-   payment provider and exact recurring-payment mechanics
-   exact launch species/crop reference seed catalog
-   exact biological reference durations/programs approved for launch
-   whether individual-animal tracking is V1 for cattle/goats/sheep
-   invoice auto-generation default vs optional farm setting
-   procurement/accounts-payable depth
-   exact file upload limits/formats
-   WhatsApp provider/template approval details
-   which Nigerian languages ship first
-   community/marketplace timing
-   EID reader, field maps and advanced live weighing sessions

The architecture must leave room for these without destabilizing core
data.

```{=html}
<!-- FILE: 38-COMMUNITY-FEEDBACK-BACKLOG.md -->
```
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

```{=html}
<!-- FILE: 39-CLAUDE-CODE-HANDOFF.md -->
```
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

## Prompt to use with Claude Code

``` text
You are implementing the Farm Management SaaS from the engineering documents in /docs/implementation.

Before coding:
1. Read 00-READ-ME-FIRST.md.
2. Read 01-SYSTEM-ARCHITECTURE.md, 02-DOMAIN-BEHAVIOUR-MATRIX.md, 03-ERD.md and 04-API-CONVENTIONS.md.
3. Read ONLY the requested phase file plus any prerequisite phase docs it explicitly depends on.
4. Inspect the existing repository and report conflicts between the repository and the locked product rules.
5. Produce a short implementation plan listing migrations, models, services, policies, requests/resources, routes, jobs/events and tests.
6. Do not implement a later phase.
7. Do not invent domain rules where the docs say configuration/research is required.
8. Keep controllers thin. Put calculations/side effects in application/domain services.
9. Every farm-owned query must be scoped. Every mutation must be authorized.
10. Add/adjust tests and run the relevant suite before declaring complete.

When complete, report:
- files changed
- migrations added
- endpoints added
- permissions/entitlements enforced
- side effects implemented
- tests added and exact test results
- unresolved assumptions
- recommended commit message
```

Then say: `Implement Phase XX only. Do not proceed beyond this phase.`

```{=html}
<!-- FILE: 40-TRACEABILITY.md -->
```
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

## Traceability

This pack consolidates four layers:

1.  **Baseline requirements** --- the original staff farm-management
    scope.
2.  **Reference behaviour** --- livestock reference app/PDF/screenshots:
    dynamic breeding, egg tray/loose quantities, records, medicine
    packaging, schedules/tasks, sales/invoices, settings patterns.
3.  **User product decisions** --- Nigeria-first onboarding, no
    organization-name screen, Farm Name + Farm Operations, crop
    planting-unit baseline, simple UI, task/template concept,
    WhatsApp/AI direction, Nigerian-language readiness.
4.  **Senior engineering design** --- hidden tenancy, capability
    metadata, event/ledger architecture, transactional side effects,
    proposed ERD/API contracts and phased implementation.

Do not claim proposed API paths/table names are copied from the
reference product. They are implementation contracts for this product.
