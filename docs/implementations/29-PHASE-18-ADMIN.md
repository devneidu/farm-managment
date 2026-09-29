# Phase 18 --- Platform administration

## Objective

Allow safe management of configuration without deployments.

## Backend scope

Platform admin for plans/entitlements, operation/species/crop master
data, capability schemas, platform work templates, settings, flags and
audit/support.

## API/UI contract

`/platform-admin/*`.

## Critical business rules

Platform admin is distinct from Farm Admin. Dangerous config edits need
validation/versioning and audit.

## Tests / acceptance

Authorization, audit, template publication, schema compatibility, plan
changes.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
