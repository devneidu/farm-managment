# Phase 6 --- Locations and production areas

## Objective

Represent farm physical context simply.

## Backend scope

Locations, production areas typed as house/pen/pond/field/plot/etc,
storage locations.

## API/UI contract

CRUD routes in API contract.

## Critical business rules

Names unique where appropriate within farm; archived areas remain
referenceable by history.

## Tests / acceptance

CRUD, archive with history, cross-farm denial.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
