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
