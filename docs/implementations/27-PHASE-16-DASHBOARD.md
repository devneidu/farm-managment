# Phase 16 --- Dashboard and insights

## Objective

Build the simple command-center dashboard represented by the approved
design direction.

## Backend scope

Dashboard aggregation service, calendar summary, deterministic insight
rules, recent activity.

## API/UI contract

`GET /dashboard`, `/dashboard/calendar`, `/insights`.

## Critical business rules

Only relevant KPIs. Crop-only farm sees crop metrics, not eggs. Worker
sees tasks/operations, not unrestricted profit. Quick Add filters by
role/operations.

## Tests / acceptance

Role variants, empty state, mixed farm, overdue tasks, low stock,
mortality threshold insight, query performance.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
