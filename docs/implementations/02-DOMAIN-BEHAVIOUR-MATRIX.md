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

  --------------------------------------------------------------------------------------------
  Context                 Selection/capability                         UI / domain behaviour
  ----------------------- -------------------------------------------- -----------------------
  Farm setup              Crops                                        Enable crop projects,
                                                                       crop records, crop
                                                                       reports; livestock-only
                                                                       UI hidden

  Farm setup              Poultry                                      Enable poultry batches,
                                                                       egg-capable
                                                                       species/config, poultry
                                                                       health/breeding
                                                                       templates

  Farm setup              Fishery                                      Enable fish batches,
                                                                       pond/location support,
                                                                       fish harvest/output

  Farm setup              Cattle/Goat/Sheep/Pig/Rabbit                 Enable livestock
                                                                       batches;
                                                                       pregnancy/birth
                                                                       workflow where
                                                                       configured

  Production              Livestock/Fish                               Initial population/head
                                                                       count required

  Production              Crop                                         Planting unit type +
                                                                       initial planting units
                                                                       required

  Crop                    Seed/seedling/stem/tuber/sucker              Material type describes
                                                                       input; it does not
                                                                       replace planting-unit
                                                                       baseline

  Breeding                Incubation-capable species                   Eggs set/incubated,
                                                                       incubation start,
                                                                       expected hatch date,
                                                                       hatch status/outcome

  Breeding                Pregnancy-capable species                    breeding/service date,
                                                                       pregnancy
                                                                       status/checks, expected
                                                                       delivery, birth outcome

  Output                  Egg-producing species                        Egg collection enabled;
                                                                       crate/tray +
                                                                       loose-piece compound
                                                                       entry supported

  Output                  Milk-producing species                       Milk collection enabled

  Record                  Feeding                                      feed item,
                                                                       quantity/unit, batch,
                                                                       date; reduce stock when
                                                                       linked

  Record                  Mortality                                    batch, quantity, cause,
                                                                       date; reduce population

  Record                  Weight                                       batch/animal,
                                                                       weight/unit, date;
                                                                       growth history

  Record                  Water                                        amount/unit,
                                                                       batch/location, date

  Health                  Vaccination/Medication/Deworming/Treatment   dynamic label; one or
                                                                       multiple medicines;
                                                                       per-medicine dose

  Medicine                packaged item                                packaging unit +
                                                                       primary unit +
                                                                       units/package

  Inventory               stock in                                     increase balance;
                                                                       optional
                                                                       supplier/expense

  Inventory               stock out/use                                decrease balance;
                                                                       reason/source

  Sale                    livestock/output/produce                     decrease applicable
                                                                       inventory/population;
                                                                       financial consequence

  Crop harvest            crop output                                  increase produce
                                                                       inventory and project
                                                                       yield

  Work                    schedule                                     generates future
                                                                       tasks/reminders; does
                                                                       not create fake
                                                                       operational records

  Work                    template applied to cycle                    materializes
                                                                       recommended task
                                                                       schedule relative to
                                                                       cycle start/age

  Task                    completion requiring evidence                opens/links relevant
                                                                       record form

  Reports                 WhatsApp delivery enabled                    report generation
                                                                       remains canonical;
                                                                       WhatsApp only
                                                                       distributes a generated
                                                                       summary/file

  AI parser               natural-language record                      parse into draft only;
                                                                       user confirms before
                                                                       domain write
  --------------------------------------------------------------------------------------------

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
