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
