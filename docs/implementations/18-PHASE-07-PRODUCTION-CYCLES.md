# Phase 7 --- Livestock batches and crop projects

## Objective

Create the production backbone.

## Backend scope

Common production cycle plus livestock/fish and crop detail. Livestock
initial population required. Crop planting-unit type and initial
planting units required; planting material type required; area and
material quantity separate.

## API/UI contract

`/production-cycles` and detail/summary/activity endpoints; UI uses
Livestock Batch or Crop Project wording.

## Critical business rules

Do not infer seed count from holes/heaps. Cycle closure locks ordinary
writes after reconciliation.

## Tests / acceptance

Crop-only/livestock/mixed; initial population movement; crop 50 heaps;
close/reopen permissions.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
