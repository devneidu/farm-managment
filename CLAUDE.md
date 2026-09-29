# Farm Management API — Claude Code Instructions

## Project

This repository is the backend API for a multi-tenant Farm Management SaaS.

The frontend is a separate application being developed independently and will consume this API.

## Stack

- Laravel 12
- PHP 8.2+
- MySQL
- API-first architecture
- API prefix: `/api/v1`
- Nigeria-first product
- Default timezone: `Africa/Lagos`
- Default currency: `NGN`
- Default language: English

## Authoritative Documentation

All product and engineering specifications are located in:

`docs/implementations/`

Before implementing any phase, read:

- `00-READ-ME-FIRST.md`
- `01-SYSTEM-ARCHITECTURE.md`
- `02-DOMAIN-BEHAVIOUR-MATRIX.md`
- `03-ERD.md`
- `04-API-CONVENTIONS.md`
- `09-SECURITY-AUDIT-QUALITY.md`
- `10-IMPLEMENTATION-MASTER-PLAN.md`

Then read the specific phase document being implemented.

Do not implement functionality from a later phase unless explicitly instructed.

## Architecture Rules

- Keep controllers thin.
- Put business logic in application/domain services.
- Use Form Requests for validation.
- Use API Resources for API responses where appropriate.
- Backend authorization is authoritative.
- Every farm-owned resource must be tenant/farm scoped.
- Never trust `farm_id` or tenant ownership supplied by the client without authorization/scoping.
- Use database transactions for operations with multiple side effects.
- Side effects must be idempotent where duplicate execution is possible.

## Identifiers

Use UUIDv7 for domain/public resource primary identifiers.

Do not expose sequential database identifiers such as 1, 2, 3 as the identity of domain resources.

Human-facing records may additionally have readable reference codes such as:

- `BAT-2026-00001`
- `CRP-2026-00001`
- `BRD-2026-00001`
- `SAL-2026-00001`
- `INV-2026-00001`

UUID and reference code serve different purposes.

## Domain Invariants

Never directly mutate derived livestock population.

Never directly mutate inventory balances.

Population changes must be explainable by population movements/events.

Inventory changes must be explainable by inventory movements.

Never treat crop planting units as seed/material quantity.

Land area, planting units, planting material consumption and harvested output are separate measurements.

Never automatically create an actual mortality, vaccination, medication, income, expense or other operational record simply because a task became due.

A task represents work that should happen.

An operational record represents what actually happened.

Preserve `recorded_at` separately from `created_at`.

Do not hard-code species behaviour throughout controllers/frontend contracts when it belongs to master data/capability configuration.

## API

All application endpoints must be versioned under:

`/api/v1`

Maintain consistent JSON response and validation/error structures.

API documentation is part of implementation, not an afterthought.

Every completed endpoint should document:

- method
- URL
- authentication
- permissions where applicable
- query/path parameters
- request body
- validation
- success response
- validation response
- relevant error responses
- example payloads

The frontend developer must have access to generated OpenAPI documentation.

## Testing

Prioritize meaningful feature and domain tests.

Test:

- business rules
- authorization
- tenant isolation
- validation
- calculations
- side effects
- idempotency
- important failure cases

Do not create low-value tests solely to increase test count.

## Phase Discipline

Before implementing a phase:

1. Read the required documentation.
2. Inspect the existing implementation.
3. Identify dependencies.
4. Report conflicts or ambiguities.
5. Present a concise implementation plan.
6. Implement only the requested phase.
7. Run relevant tests.
8. Report exact results.

Do not continue into the next phase automatically.

## Completion Report

At the end of each implementation phase report:

- files created/changed
- migrations added
- models/services added
- endpoints added
- permissions/entitlements enforced
- side effects implemented
- API documentation changes
- tests added
- exact test results
- unresolved assumptions/issues
- recommended Git commit message