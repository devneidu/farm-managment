# Phase 3 --- Subscription and entitlements

## Objective

Create a configurable commercial gate without coupling farm logic to
plan names.

## Backend scope

Plans, prices, entitlements, plan entitlements, subscription, billing
transactions/events, entitlement service and usage resolvers. Provider
abstraction; payment integration may be completed later if provider not
yet chosen.

## API/UI contract

`/public/plans`, `/subscription`, `/subscription/entitlements`,
`/subscription/usage`, checkout/cancel/resume.

## Critical business rules

No `if plan == pro`. Downgrades do not delete historical farm data.
Numeric limits must be concurrency-safe.

## Tests / acceptance

Boolean gate, numeric limit, upgrade unlock, downgrade over-limit,
tenant isolation, duplicate webhook idempotency.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
