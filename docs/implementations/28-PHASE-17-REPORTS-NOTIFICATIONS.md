# Phase 17 --- Reports, exports, notifications and audit

## Objective

Make farm history usable and shareable.

## Backend scope

Report queries, queued exports, PDF/XLSX/CSV, notification
center/preferences, audit views.

## API/UI contract

Reports/exports/notifications endpoints.

## Critical business rules

Exports are farm-scoped and private. Report calculations reconcile to
authoritative ledgers/events.

## Tests / acceptance

Export permissions; large queued export; report totals; audit
visibility; notification read states.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
