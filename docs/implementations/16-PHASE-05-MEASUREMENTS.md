# Phase 5 --- Measurements and conversions

## Objective

Implement trustworthy units and compound quantities before
inventory/records.

## Backend scope

Dimensions, units, farm preferences, package conversions, normalization
service and conversion snapshots.

## API/UI contract

`/master/units`, `/settings/units`, `/settings/package-conversions`.

## Critical business rules

Never assume bag/crate size globally. Preserve entered representation
and normalized quantity.

## Tests / acceptance

3 crates +14 with 30/crate =104; incompatible dimension rejected;
historical conversion snapshot unaffected by later config edit.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
