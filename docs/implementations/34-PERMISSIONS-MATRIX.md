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
