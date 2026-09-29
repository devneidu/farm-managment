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

## Reporting

Report families: - production/cycle performance - livestock
population/mortality/growth - egg/milk/output - crop establishment,
activities, loss, harvest/yield - breeding outcomes - health/treatment -
inventory/feed/input consumption - sales/invoices/payments -
income/expenses/profitability - task completion/compliance -
contacts/customer/supplier history - audit/activity

Exports: CSV/XLSX/PDF where appropriate.

## WhatsApp --- phased enhancement

WhatsApp is not the source of truth. The reporting service generates the
report first, then a WhatsApp delivery adapter distributes a
summary/link/PDF where provider capabilities permit.

Initial useful flows: - owner receives daily/weekly farm summary -
critical alert: mortality threshold, low stock, overdue critical task -
manually share invoice/report - scheduled management report

Keep provider credentials encrypted and webhooks verified/idempotent.

## AI parsing --- later

Examples: "Used 2 bags of starter feed for Broiler Batch A this
morning." "Sold 5 crates of eggs to Emeka for ₦28,000." "3 birds died in
House 2."

Pipeline: text → parser → structured **draft** → validation → user
confirmation → canonical domain service.

Never let an LLM directly mutate inventory/population/finance without
confirmation and server-side validation.

## Nigerian localization

Architecture should support locale keys from day one. Launch language is
English. Nigerian-language packs can be added progressively (for example
Hausa, Yoruba, Igbo and Nigerian Pidgin) after terminology review by
native speakers. Farm data itself is not machine-translated by default.
Currency defaults to NGN.
