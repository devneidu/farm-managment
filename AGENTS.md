# Farm Management API — Coding Agent Instructions

## Purpose

This repository contains the backend API for the Farm Management SaaS.

The frontend is a separate application and consumes this API.

Multiple coding agents may work on this repository, including Codex and
Claude Code.

No agent should assume it has exclusive ownership of the project.

## Stack

- Laravel 12
- PHP 8.2+
- MySQL
- API-first architecture
- API prefix: `/api/v1`
- Separate frontend application
- Nigeria-first defaults
- Default timezone: `Africa/Lagos`
- Default currency: `NGN`
- Default language: English

## Source of Truth

Engineering specifications are located in:

`docs/implementations/`

Before implementing a phase, read:

- `00-READ-ME-FIRST.md`
- `01-SYSTEM-ARCHITECTURE.md`
- `02-DOMAIN-BEHAVIOUR-MATRIX.md`
- `03-ERD.md`
- `04-API-CONVENTIONS.md`
- `09-SECURITY-AUDIT-QUALITY.md`
- `10-IMPLEMENTATION-MASTER-PLAN.md`

Then read only the document for the phase being implemented.

Do not repeatedly scan the entire documentation directory unless necessary.

## Mandatory Handoff

Before beginning work, ALWAYS read:

`WORKLOG.md`

It contains the current implementation state and handoff from the previous
coding agent.

Never assume a task described in documentation has already been implemented.
Verify the repository.

When finishing meaningful work, update `WORKLOG.md`.

This is mandatory because another coding agent may continue immediately.

## Phase Discipline

Implement only the requested phase/task.

Do not automatically proceed into the next phase.

Before implementation:

1. Read WORKLOG.md.
2. Read relevant engineering documents.
3. Inspect existing code related to the task.
4. Check git status and recent relevant changes.
5. Identify unfinished work from the previous agent.
6. Present a concise implementation plan when requested.

## Architecture Rules

- Keep controllers thin.
- Use Form Requests for validation.
- Use API Resources where appropriate.
- Put substantial business logic in appropriate application/domain services.
- Backend authorization is authoritative.
- Farm-owned resources must always be correctly scoped.
- Never trust client-supplied farm ownership without authorization.
- Use database transactions for operations with multiple dependent side effects.
- Operations susceptible to retries/duplicates must be idempotent where required.
- Do not add abstractions without a concrete reason.

## Identifiers

Domain/public resources use UUIDv7 identifiers.

Do not expose sequential integer IDs as public domain-resource identities.

Human-facing records may additionally have reference codes such as:

- BAT-2026-00001
- CRP-2026-00001
- BRD-2026-00001
- SAL-2026-00001
- INV-2026-00001

UUIDs and reference codes serve different purposes.

## Critical Domain Rules

Do not directly mutate derived livestock population.

Population changes must be explainable through population movements/events.

Do not directly mutate inventory balances.

Inventory changes must be explainable through inventory movements.

Crop planting units are NOT seed/material quantities.

Land area, planting units, planting material consumption and harvest output
are separate measurements.

Tasks describe work that SHOULD happen.

Operational records describe what ACTUALLY happened.

A scheduled task must never automatically create fake mortality,
vaccination, medication, income, expense or similar operational records.

Preserve `recorded_at` separately from `created_at`.

Prefer metadata/configuration-driven operation and species behavior rather
than large amounts of hard-coded conditional logic.

## API Contract

All application API endpoints are versioned under:

`/api/v1`

Maintain consistent JSON responses and errors.

Every completed endpoint must be documented sufficiently for the separate
frontend developer to integrate without reading backend code.

Documentation should include:

- method
- URL
- authentication
- required permission where applicable
- path/query parameters
- request body
- validation rules
- example request
- success response
- validation response
- relevant error responses

Generated OpenAPI documentation is part of the backend deliverable.

When implementation changes an API contract, update the API documentation
in the same task.

## Testing

Prioritize tests that protect real behavior:

- business rules
- authorization
- tenant/farm isolation
- validation
- calculations
- side effects
- idempotency
- state transitions
- important failure cases

Do not generate low-value tests merely to increase test count.

Use targeted tests during development.

Run the relevant complete suite before declaring the task finished.

## Git Safety

Before editing:

`git status`

Never overwrite unrelated uncommitted work.

Do not revert another agent's changes simply because you would have
implemented them differently.

Do not automatically commit unless explicitly instructed.

## Token Efficiency

Do not repeatedly read large documents already read during the same task.

Do not repeatedly run the entire test suite while debugging one test.

Do not investigate unrelated modules.

Do not refactor unrelated working code during a scoped task.

Do not install packages experimentally without first verifying the need and
compatibility.

## Definition of Done

A task is not complete merely because the code works.

Where applicable it must include:

- implementation
- validation
- authorization
- tenant/farm isolation
- side effects
- API documentation
- meaningful tests
- passing relevant test suite
- WORKLOG.md update

## Completion

At the end of meaningful work:

1. Run relevant tests.
2. Review git diff.
3. Update WORKLOG.md.
4. Record remaining issues honestly.
5. Recommend a Git commit message.
6. Stop unless explicitly instructed to continue.