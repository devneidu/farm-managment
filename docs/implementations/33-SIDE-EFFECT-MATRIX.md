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

  -----------------------------------------------------------------------
  User action                         Required system effects
  ----------------------------------- -----------------------------------
  Egg collection / milk record        normalize quantity; production
                                      history; automatic stock-in on the
                                      farm's Eggs / Milk item (reason
                                      production, same transaction);
                                      analytics

  Eggs / milk / feed donated,         inventory increase ONLY (no
  purchased or received               egg_collection / milk record)

  Eggs put into incubation            breeding project + stock-out
                                      (reason incubation) in one
                                      transaction; cancel returns eggs
                                      only when the user says so

  Eggs / milk / feed given away,      inventory decrease ONLY (no sale,
  spoiled, lost or used at home       no income)

  Mortality                           population decrease; mortality
                                      metrics; alert evaluation; audit

  Feeding                             feed_use record + feed stock
                                      decrease (reason production_use)
                                      in one entry; consumption
                                      history; cost allocation if
                                      configured

  Crop harvest                        produce inventory increase;
                                      yield/progress update

  Livestock sale/exit                 population decrease; sale/finance
                                      link

  Produce/output/feed sale            inventory decrease (one sale
                                      stock-out per line); sale/finance
                                      link

  Purchase/stock-in                   inventory increase; optional
                                      expense/payable

  Medicine administration             health history; medicine stock
                                      decrease; withdrawal/follow-up if
                                      configured

  Start breeding                      expected date/milestones

  Successful breeding outcome         outcome metrics; explicit offspring
                                      registration/population effect

  Complete evidence task              linked operational record; task
                                      completion

  Close cycle                         reconcile; validate; protect
                                      ordinary edits

  Reverse record                      compensating movements; audit; no
                                      history deletion
  -----------------------------------------------------------------------

All multi-effect actions are transactional.
