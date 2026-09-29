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
