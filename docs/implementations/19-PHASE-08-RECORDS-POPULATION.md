# Phase 8 --- Operational records and population

## Objective

Capture real-world farm events and derive population safely.

## Backend scope

Canonical operational record service, type-specific validators/detail
payloads, attachments, population movements. Implement feed use, egg
collection, milk, mortality, weight, temperature, water and core crop
activity shells.

## API/UI contract

`GET|POST /records`, schema endpoint, reverse endpoint, attachments.

## Critical business rules

Mortality reduces population; egg collection increases output stock only
when inventory integration is enabled; records have `recorded_at`;
corrections use reversal/adjustment.

## Tests / acceptance

Dynamic validation; insufficient population; idempotent side effects;
attachment policy; reversal restores derived effects.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
