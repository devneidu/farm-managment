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
