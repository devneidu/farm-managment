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
