# Phase 20 --- WhatsApp and AI parsing

## Objective

Add high-value integrations after canonical farm workflows are stable.

## Backend scope

WhatsApp adapter/settings/report delivery, verified webhook if inbound
is later enabled; AI parser producing typed drafts; confirmation
workflow.

## API/UI contract

Integration endpoints in API contract.

## Critical business rules

WhatsApp distributes canonical reports/alerts. AI never writes
authoritative records directly. Confirmation invokes same domain
services as normal UI.

## Tests / acceptance

Provider failure/retry; duplicate webhook; parser ambiguity;
confirmation; permission and entitlement gates.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
