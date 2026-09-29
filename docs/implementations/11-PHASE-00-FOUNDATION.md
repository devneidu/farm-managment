# Phase 0 --- Foundation

## Objective

Create a clean API-first Laravel foundation that later domain phases can
safely build on.

## Backend scope

Configure environment strategy, MySQL, queues, mail, private/public
storage, API versioning, exception JSON, request IDs, UTC persistence,
Africa/Lagos presentation defaults, factories, seeders, test DB, CI and
static/code-quality tooling. Establish application/domain service
conventions and transaction helpers.

## API/UI contract

`/api/v1/health` plus standard error/validation envelope. No farm domain
UI yet.

## Critical business rules

No business feature should be implemented here.
IDs/timestamps/money/quantity conventions must be documented.

## Tests / acceptance

Health endpoint; CI boots app; test DB works; queue/storage/mail fakes
work; API validation/error shapes tested.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
