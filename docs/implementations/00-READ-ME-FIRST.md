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
