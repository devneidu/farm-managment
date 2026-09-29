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
