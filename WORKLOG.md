# Farm Management API — Agent Worklog

> Shared handoff document for Claude Code, Codex, and other coding agents.
>
> Every agent MUST read this file before working and update it after
> completing meaningful work.
>
> Keep this document concise. It is a handoff document, not a diary.

---

## Current Status

**Current Phase:** Phase 1 — Authentication, identity & initial farm onboarding

**Status:** Implemented and verified; NOT committed (user reviews/commits). Phase 0 is committed (`dce84b6`).

**Last Agent:** Claude Code (Sonnet 5.5)

**Last Updated:** 2026-09-29

---

## Environment

- Laravel 12 / PHP 8.2 / MySQL / API prefix `/api/v1` / separate React frontend / engineering docs in `docs/implementations/`
- Dev API `http://localhost:8000`. Frontend origins are configured by env (`CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`).

---

## Important Decisions (Phase 0 — still valid)

- IDs: domain resources use UUIDv7 via `App\Models\Concerns\HasUuidV7` (`$table->uuid('id')->primary()`).
- API envelope `{data, meta, message}` via `App\Support\Api\ApiResponse::success()`; errors `{message, code, request_id, errors?}` via `ApiExceptionRenderer`.
- Databases: `farm_management` = dev only; `farm_management_test` = disposable, used by PHPUnit (forced in phpunit.xml; `tests/TestCase.php` throws otherwise). NEVER `migrate:fresh` against `farm_management`; use `DB_DATABASE=farm_management_test php artisan migrate:fresh`.
- Queue/cache/session on the `database` driver; no Redis. No CI yet. Time is UTC; default presentation tz Africa/Lagos.
- Docs: dedoc/scramble 0.13.45 pinned; UI `/docs/api` (local env), committed export `docs/api/openapi.json` (regen: `php artisan scramble:export --path=docs/api/openapi.json`).

## Phase 1 Architecture

**Auth = Sanctum first-party SPA cookie session** (no bearer tokens for the browser). `bootstrap/app.php` calls `statefulApi()`; a request is stateful when its `Origin`/`Referer` host is in `SANCTUM_STATEFUL_DOMAINS`. CSRF: `GET /api/v1/auth/csrf-cookie` (own route in routes/api/v1.php; `sanctum.routes=false`), then the `X-XSRF-TOKEN` header on writes. CORS `supports_credentials` defaults to true. Requests without a session (non-stateful origin) get `400 stateful_request_required` from `SessionAuthenticator::assertStateful` (checked before any account is created). The Sanctum `personal_access_tokens` table exists (uuidMorphs) and `User` uses `HasApiTokens`, so mobile/API tokens can be added later without touching browser auth (nothing issues tokens yet). Logout ends the current session only. Password reset revokes all tokens and (only when `SESSION_DRIVER=database`) all DB sessions of the user.

**Register/login log the user in even when the email is unverified** (restricted state). The frontend routes from `next_action` (`verify_email` | `complete_farm_setup` | `none`), returned by every auth endpoint and `GET /auth/me` (`App\Http\Resources\AuthStateResource`).

**Middleware:** aliases `account.active` (suspended -> 403 account_suspended; `users.suspended_at` is groundwork only, no admin UI), `email.verified` (403 email_verification_required), `onboarded` (403 onboarding_required); group `app.access` = auth:sanctum + all three. ALL Phase 2+ farm-management routes go in the empty `Route::middleware('app.access')` group at the bottom of routes/api/v1.php. Tests register probe routes with `['api','app.access']` (the `api` middleware is required for route-model binding).

**OTP (`App\Services\Auth\OtpService`, table `one_time_codes`):** purpose enum `App\Enums\OtpPurpose` (email_verification, password_reset, password_reset_authorization). Only HMAC-SHA256(app key, purpose|user_id|secret) is stored. TTL 10 min, 5 attempts then dead, 60 s resend cooldown, a new code supersedes older active ones, consumed on success. Config in `config/identity.php` (env `OTP_*`). Mail is sent synchronously via `OtpNotification` (deliberately not queued: a queued notification would put the plaintext code in the jobs table). A mail failure is reported without the code and never breaks the response. Local mail: `MAIL_MAILER=log` -> storage/logs/laravel.log.

**Password reset:** `forgot` (always 200 generic; silent no-op for unknown/suspended/cooldown) -> `verify-otp` (consumes the OTP, returns a 64-char single-use `reset_token`, 15 min, stored hashed) -> `reset` (consumes token, sets password, invalidates codes, revokes tokens/sessions). Known trade-off: mail is synchronous so response timing can differ between existing and unknown emails; mitigated by strict rate limits.

**Google (`GoogleAuthService`, interface `GoogleIdentityVerifier`, impl `JwtGoogleIdentityVerifier`):** the frontend sends a Google Identity Services ID token as `credential`; the backend verifies the RS256 signature against Google's JWKS (cached 1h), issuer, audience = `GOOGLE_CLIENT_ID` (`services.google.client_id`), expiry, and requires `email` + `email_verified`. Match by `social_accounts(provider, provider_user_id = sub)`, else link to the existing user by email, else create (verified, password null). Linking an UNVERIFIED password account nulls its password and invalidates its OTPs (pre-hijack defence). Google users get no app OTP and still need farm setup. Tests fake the interface (no external calls). Unset client id -> 503 google_unavailable.

**Farm/ownership:** `farms` (uuid, name, country_code NG, currency NGN, timezone Africa/Lagos, locale en) + `farm_memberships` (uuid, farm_id, user_id, role='owner', unique farm+user). No `tenants` table yet (the ERD lists one; deliberately deferred — add with subscriptions/RBAC; no customer-facing tenant/organization terms). `FarmOnboardingService` locks the user row, throws 409 already_onboarded if `onboarded_at` is set, and creates farm + owner membership + sets `onboarded_at` in one transaction. `FarmPolicy::view` = membership. `User::currentFarm()`.

**Rate limiters** (`App\Support\Auth\AuthRateLimiters`, numbers in config/identity.php `rate_limits`): auth-register, auth-login (email+ip and ip), auth-otp-verify / auth-otp-resend (per user), auth-forgot-password, auth-reset-verify, auth-reset-password, auth-google. 429 -> standard envelope + Retry-After.

**API errors/docs:** `App\Support\Api\ApiHttpException(status, code, message, headers)` renders through `ApiExceptionRenderer` with a custom `code`. OpenAPI: `App\Support\Api\Docs\{ApiErrorResponse, ApiHttpExceptionToResponseExtension, AuthFlowResponsesExtension}` (registered in config/scramble.php) document error envelopes (401/403/409/419/429); the security scheme is the session cookie (AppServiceProvider, global; public routes use `@unauthenticated`). Use `#[Scramble\Attributes\Response(status: 201, type: '...')]` (not `@status`) for non-200 success responses, and FQCNs inside those type strings (Pint removes imports that are only referenced there).

---

## Last Completed Task

Phase 1 — Authentication + initial farm onboarding.

## Endpoints (all under `/api/v1`)

Public: `GET /auth/csrf-cookie`, `POST /auth/register`, `POST /auth/login`, `POST /auth/google`, `POST /auth/password/forgot`, `POST /auth/password/verify-otp`, `POST /auth/password/reset`.
Authenticated (session): `GET /auth/me`, `POST /auth/logout`, `POST /auth/email/verify`, `POST /auth/email/resend`.
Verified email: `POST /onboarding/farm` (body `{name}` only).
Also `GET /health` (Phase 0). Frontend guide: `docs/api/README.md` ("Frontend authentication guide").

## Files Changed

Created: config/identity.php, config/sanctum.php; app/Enums/OtpPurpose.php; app/Models/{Farm,FarmMembership,OneTimeCode,SocialAccount}.php; app/Policies/FarmPolicy.php; app/Services/Auth/{OtpService,OtpDelivery,SessionAuthenticator,GoogleAuthService,PasswordResetService}.php + Google/*; app/Services/Farm/FarmOnboardingService.php; app/Notifications/OtpNotification.php; app/Http/Middleware/{EnsureAccountIsActive,EnsureEmailIsVerified,EnsureOnboarded}.php; app/Http/Requests/{Auth/*,Onboarding/*,Concerns/NormalizesEmail}.php; app/Http/Resources/AuthStateResource.php; app/Http/Controllers/Api/V1/Auth/*, OnboardingController.php; app/Support/Api/{ApiHttpException,Docs/*}.php; app/Support/Auth/AuthRateLimiters.php; 5 migrations; tests/Feature/Auth/*.
Modified: User model, UserFactory, AppServiceProvider, ApiExceptionRenderer, bootstrap/app.php, routes/api/v1.php, config/{cors,services,scramble}.php, .env.example, phpunit.xml, composer.json/lock, docs/api/{README.md,openapi.json}, tests/Feature/Api/ApiDocumentationTest.php, WORKLOG.md.

## Database Changes

Migrations: `personal_access_tokens` (Sanctum, uuidMorphs); `convert_identity_tables_to_uuid` (drops/recreates `users` [uuid id, nullable name, unique email, nullable password, email_verified_at, onboarded_at, suspended_at] and `sessions` [uuid user_id]; drops `password_reset_tokens`; REFUSES to run if users has rows; reversible); `farms` + `farm_memberships`; `one_time_codes`; `social_accounts`. Applied to the dev DB `farm_management` with a plain `migrate` (0 users existed). Rollback and re-migrate verified on `farm_management_test`.

## Tests

83 tests, 445 assertions, all passing (`php artisan test`); Pint clean for app/database/tests. A live `php artisan serve` check (run against the test DB) confirmed: CSRF cookie, 419 without the token, register with the token, /auth/me, onboarding blocked while unverified, logout.

## Packages Added

`laravel/sanctum` ^4.3 (SPA cookie auth + future tokens), `firebase/php-jwt` ^7.2 (verify the Google ID token signature via JWKS). `dedoc/scramble` is from Phase 0.

## Open Issues / Blockers

- `GOOGLE_CLIENT_ID` must be set in the backend `.env` for real Google login; the verifier is tested with a locally generated key pair, not against Google itself, so real Google login is untested end-to-end.
- Pre-existing Pint style issues remain in skeleton files `bootstrap/providers.php`, `config/auth.php` and some config files (untouched).
- The local dev `.env` was not edited; defaults cover localhost:3000/5173 for Sanctum and CORS credentials. Add `SANCTUM_STATEFUL_DOMAINS` there if the frontend runs elsewhere.
- Production still needs: SESSION_DOMAIN / SESSION_SECURE_COOKIE, a real MAIL_* provider, and a docs-exposure decision (Scramble is gated to local).
- Scramble is pre-1.0; keep it pinned.
- Not built (out of scope): "password changed" notice email, admin suspension, device/session management, mobile token issuing.

---

## Next Task

Phase 2 — see `docs/implementations/10-IMPLEMENTATION-MASTER-PLAN.md` and the phase-2 doc. Do not start until the user says so. New farm-management routes MUST go inside the `app.access` middleware group.

## Recommended Next Commit

`feat: add Phase 1 authentication, Google sign-in and farm onboarding (Sanctum SPA cookie auth)`

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
