# Farm Management API — Agent Worklog

> Shared handoff document for Claude Code, Codex, and other coding agents.
>
> Every agent MUST read this file before working and update it after
> completing meaningful work.
>
> Keep this document concise. It is a handoff document, not a diary.

---

## Current Status

**Current Phase:** Phase 0 — Foundation

**Status:** Implemented and verified; awaiting user review/commit (not committed)

**Last Agent:** Claude Code (Sonnet 5.5)

**Last Updated:** 2026-09-29

---

## Environment

- Laravel: 12.x
- PHP: 8.2+
- Database: MySQL
- API Prefix: `/api/v1`
- Frontend: Separate application
- Engineering docs: `docs/implementations/`

---

## Completed

- Laravel 12 application installed.
- MySQL database created and connected.
- Initial Laravel migrations executed.
- Engineering documentation copied into `docs/implementations/`.
- `CLAUDE.md` created.
- `AGENTS.md` created.
- Shared agent handoff process established.

---

## Currently In Progress

Nothing. Phase 0 is implemented but NOT committed; the user reviews and commits.

---

## Next Task

Phase 1 — Identity, authentication, hidden tenancy, farm setup.
Read `docs/implementations/12-PHASE-01-AUTH-ONBOARDING.md` first. Do not start until the user says so.
Phase 1 should add Sanctum and then update the Scramble security scheme + 401/403 docs.

---

## Important Decisions

- IDs: domain resources use UUIDv7 via `App\Models\Concerns\HasUuidV7` (migration: `$table->uuid('id')->primary()`). Reference codes (BAT-2026-00001) come later, separate columns.
- API: prefix `/api/v1`, routes in `routes/api/v1.php`. Success envelope `{data, meta, message}` via `App\Support\Api\ApiResponse::success()`.
- Errors (all `api/*`, regardless of Accept): `{message, code, request_id, errors?}` via `App\Support\Api\ApiExceptionRenderer`. Codes: validation_failed, unauthenticated, forbidden, not_found, method_not_allowed, conflict, too_many_requests, server_error. Internals never exposed (500 shows real message only when APP_DEBUG=true).
- Request IDs: `AssignRequestId` (global middleware) -> `X-Request-Id` header, `request_id` in errors and log context.
- Time: DB/app in UTC; `config('app.default_farm_timezone')` = Africa/Lagos is only the default presentation timezone (farms may override later).
- Databases: `farm_management` = dev only. `farm_management_test` = disposable, used by PHPUnit (forced in phpunit.xml). `tests/TestCase.php` throws if the DB name does not end in `_test`. NEVER run migrate:fresh against `farm_management`; for destructive checks use `DB_DATABASE=farm_management_test php artisan migrate:fresh`.
- Queue/cache/session stay on the `database` driver; no Redis. No CI yet (deliberately deferred until before staging). Laravel Boost deliberately not installed.
- CORS: `config/cors.php`, `api/*`, origins from `CORS_ALLOWED_ORIGINS` (comma-separated env; empty = none). `X-Request-Id` exposed.
- Docs: dedoc/scramble 0.13.45 (pinned, pre-1.0). UI `/docs/api`, JSON `/docs/api.json` (local env only by default), committed export `docs/api/openapi.json`. See `docs/api/README.md`.

---

## Last Completed Task

Phase 0 — Foundation.

## Files Changed By Last Agent

Created: routes/api/v1.php, app/Http/Controllers/Api/V1/HealthController.php, app/Support/Api/{ApiResponse,ApiExceptionRenderer}.php, app/Http/Middleware/AssignRequestId.php, app/Models/Concerns/HasUuidV7.php, config/cors.php, config/scramble.php, docs/api/{README.md,openapi.json}, tests/Feature/Api/{HealthEndpointTest,ApiErrorFormatTest,ApiDocumentationTest}.php, tests/Feature/UuidV7ConventionTest.php.
Modified: bootstrap/app.php, config/app.php, phpunit.xml, .env.example, tests/TestCase.php, composer.json/lock, WORKLOG.md.
Deleted: default tests/Feature/ExampleTest.php, tests/Unit/ExampleTest.php.

## Database Changes

No migrations. Created empty MySQL database `farm_management_test` (utf8mb4_unicode_ci). `farm_management` untouched.

## API Changes

`GET /api/v1/health` (public). Docs at `/docs/api`, `/docs/api.json`.

## Tests

12 tests, 58 assertions, all passing (`php artisan test`). Tests cover health envelope, request id, CORS, JSON errors (404/405/422/401/403/500), UUIDv7, OpenAPI export, test DB isolation.

## Packages Added

`dedoc/scramble` 0.13.45 — OpenAPI 3.1 generation from code (plus its deps phpdoc-parser, laravel-package-tools).

## Open Issues / Blockers

- `vendor/bin/pint --test` reports pre-existing style issues in the untouched skeleton file app/Models/User.php (and a few skeleton files); not changed to keep the diff scoped. Run pint on them when Phase 1 touches User.
- Scramble docs are gated to local env; production exposure decision deferred.
- Scramble is pre-1.0; keep the version pinned and review on upgrade.
- OpenAPI `info.title` comes from APP_NAME; re-export docs/api/openapi.json if it changes.
- Phase doc mentions CI, storage/mail conventions, seeders, transaction helpers: intentionally deferred/skipped (no need yet).

---

## Handoff Notes

Keep controllers thin, validate with Form Requests, and document every endpoint through Scramble. Re-export `docs/api/openapi.json` after endpoint changes.

---

## Recommended Next Commit

`feat: add Phase 0 API foundation (v1 routing, JSON conventions, UUIDv7, OpenAPI docs)`

---
# Update Template

When finishing work, replace/update the sections above.

At minimum record:

**Last Agent:**
Claude Code / Codex / other

**Completed:**
What was actually completed.

**Currently In Progress:**
Anything partially implemented.

**Files Changed:**
Important files created/modified.

**Database Changes:**
Migrations/schema changes.

**API Changes:**
Endpoints or API contracts added/changed.

**Tests:**
Tests added and exact latest result.

**Packages Added:**
Any Composer/NPM packages and why.

**Open Issues / Blockers:**
Anything the next agent needs to know.

**Next Task:**
The exact next logical task.

**Recommended Commit:**
Suggested commit message.