# Farm Management API — Agent Worklog

> Shared handoff document for Claude Code, Codex, and other coding agents.
>
> Every agent MUST read this file before working and update it after
> completing meaningful work.
>
> Keep this document concise. It is a handoff document, not a diary.

---

## Current Status

**Current Phase:** Phase 2 — RBAC, Team Access & Core Settings

**Status:** Implemented and verified; NOT committed (user reviews/commits). Phases 0 (`dce84b6`) and 1 (`1723f1a`) are committed.

**Last Agent:** Claude Code (Sonnet 5.5)

**Last Updated:** 2026-09-30

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

**Register/login log the user in even when the email is unverified** (restricted state). The frontend routes from `next_action` (`verify_email` | `complete_farm_setup` | `no_active_farm` | `none`; plus `onboarded` and `has_active_farm` booleans), returned by every auth endpoint and `GET /auth/me` (`App\Http\Resources\AuthStateResource`).

**Middleware:** aliases `account.active` (suspended -> 403 account_suspended; `users.suspended_at` is groundwork only, no admin UI), `email.verified` (403 email_verification_required), `onboarded` (403 onboarding_required); group `app.access` = auth:sanctum + all three. ALL Phase 2+ farm-management routes go in the empty `Route::middleware('app.access')` group at the bottom of routes/api/v1.php. Tests register probe routes with `['api','app.access']` (the `api` middleware is required for route-model binding).

**OTP (`App\Services\Auth\OtpService`, table `one_time_codes`):** purpose enum `App\Enums\OtpPurpose` (email_verification, password_reset, password_reset_authorization). Only HMAC-SHA256(app key, purpose|user_id|secret) is stored. TTL 10 min, 5 attempts then dead, 60 s resend cooldown, a new code supersedes older active ones, consumed on success. Config in `config/identity.php` (env `OTP_*`). Mail is sent synchronously via `OtpNotification` (deliberately not queued: a queued notification would put the plaintext code in the jobs table). A mail failure is reported without the code and never breaks the response. Local mail: `MAIL_MAILER=log` -> storage/logs/laravel.log.

**Password reset:** `forgot` (always 200 generic; silent no-op for unknown/suspended/cooldown) -> `verify-otp` (consumes the OTP, returns a 64-char single-use `reset_token`, 15 min, stored hashed) -> `reset` (consumes token, sets password, invalidates codes, revokes tokens/sessions). Known trade-off: mail is synchronous so response timing can differ between existing and unknown emails; mitigated by strict rate limits.

**Google (`GoogleAuthService`, interface `GoogleIdentityVerifier`, impl `JwtGoogleIdentityVerifier`):** the frontend sends a Google Identity Services ID token as `credential`; the backend verifies the RS256 signature against Google's JWKS (cached 1h), issuer, audience = `GOOGLE_CLIENT_ID` (`services.google.client_id`), expiry, and requires `email` + `email_verified`. Match by `social_accounts(provider, provider_user_id = sub)`, else link to the existing user by email, else create (verified, password null). Linking an UNVERIFIED password account nulls its password and invalidates its OTPs (pre-hijack defence). Google users get no app OTP and still need farm setup. Tests fake the interface (no external calls). Unset client id -> 503 google_unavailable.

**Farm/ownership:** `farms` (uuid, name, country_code NG, currency NGN, timezone Africa/Lagos, locale en) + `farm_memberships` (uuid, farm_id, user_id, role='owner', unique farm+user). No `tenants` table yet (the ERD lists one; deliberately deferred — add with subscriptions/RBAC; no customer-facing tenant/organization terms). `FarmOnboardingService` locks the user row, throws 409 already_onboarded if `onboarded_at` is set, and creates farm + owner membership + sets `onboarded_at` in one transaction. `FarmPolicy::view` = membership. `User::currentFarm()`.

**Rate limiters** (`App\Support\Auth\AuthRateLimiters`, numbers in config/identity.php `rate_limits`): auth-register, auth-login (email+ip and ip), auth-otp-verify / auth-otp-resend (per user), auth-forgot-password, auth-reset-verify, auth-reset-password, auth-google. 429 -> standard envelope + Retry-After.

**API errors/docs:** `App\Support\Api\ApiHttpException(status, code, message, headers)` renders through `ApiExceptionRenderer` with a custom `code`. OpenAPI: `App\Support\Api\Docs\{ApiErrorResponse, ApiHttpExceptionToResponseExtension, AuthFlowResponsesExtension}` (registered in config/scramble.php) document error envelopes (401/403/409/419/429); the security scheme is the session cookie (AppServiceProvider, global; public routes use `@unauthenticated`). Use `#[Scramble\Attributes\Response(status: 201, type: '...')]` (not `@status`) for non-200 success responses, and FQCNs inside those type strings (Pint removes imports that are only referenced there).

---

## Phase 2 Architecture (RBAC, Team & Access, settings)

**RBAC decision: no package.** Spatie Laravel Permission is global/team-column oriented and would duplicate `farm_memberships`; a role→permission map on the membership is simpler and fully farm-scoped. Roles/permissions are PHP enums, so no schema change is needed to add permissions:
- `App\Enums\FarmRole` (owner, manager, farm_worker, finance): `permissions()` is THE role→permission mapping; also `assignableRoles()` (owner: manager/farm_worker/finance; manager: farm_worker/finance; others none) and `canManage($target)`. **Owner is never assignable** (ownership transfer is NOT supported; deliberately no half-built flow).
- `App\Enums\Permission`: `farm.view/update`, `team.view/invite/update_role/remove`, plus reserved `livestock.batch.create`, `inventory.adjust`, `finance.expense.create` (identifiers only, no routes). To add a permission: add a case, grant it in `FarmRole::permissions()`, protect the route with `farm.permission:<id>`. Owner automatically gets every case.
- `App\Enums\MembershipStatus` (active|removed). Vet preset, custom roles and a "sensitive settings" permission were NOT built (not needed yet).

**Farm scoping (never trust a farm id):** route group `['app.access','farm.context']` in routes/api/v1.php. `ResolveFarmContext` (alias `farm.context`) picks the caller's oldest ACTIVE membership (optional `X-Farm-Id` header selects another active one; otherwise `403 farm_access_denied` / `no_active_farm`) and binds `App\Support\Access\FarmContext` (farm + membership; `can()`/`authorize()`) into the container. `RequireFarmPermission` (alias `farm.permission:x`) enforces the permission. Controllers type-hint `FarmContext`; member/invitation lookups are scoped through `$ctx->farm->memberships()->active()` / `->invitations()` so cross-farm ids are 404. Routes have no farm id in the URL. `User::farms()`/`Farm::users()`/`currentFarm()`/`FarmPolicy::view` now only consider ACTIVE memberships. New farm-management routes MUST go in that group.

**Owner protections (`App\Services\Team\TeamService` + `InvitationService::assertCanGrant`):** nobody can assign owner (`422 ownership_transfer_unsupported`); manager can't touch owner/managers (`403 insufficient_role`); no self role change/removal (`403 cannot_change_own_role|cannot_remove_self`); last active owner can't be demoted/removed (`409 last_owner`, locked count). **State model (locked):** email verification, onboarding completion (`onboarded_at`), active farm membership and current farm are four DISTINCT states. `onboarded_at` = "completed initial onboarding"; removing a membership NEVER clears it (a user can be authenticated+verified+onboarded with no active farm: `AuthStateResource` returns `has_active_farm:false`, `farm:null`, `next_action:no_active_farm`; farm routes give `403 no_active_farm`; `POST /onboarding/farm` stays `409 already_onboarded`; no no-farm frontend workflow built). The only place membership sets `onboarded_at` is invitation acceptance by a verified, never-onboarded user (they enter via an existing farm; no farm is created); it is never derived from membership count.

**Invitations (`InvitationService`, table `farm_invitations`):** email, role, invited_by, expires_at (7 days, `identity.invitations.ttl_days`), accepted_at/by, revoked_at/by, sha256 `token_hash` (plaintext only in the email; sent synchronously via on-demand `FarmInvitationNotification`, link `{FRONTEND_URL}/invitations/accept?token=`). Invite: farm row locked; `409 already_member`, `409 invitation_already_pending` (expired/revoked can be re-issued); resend rotates token+expiry; revoke; accept (`POST /invitations/accept`, verified user whose email == invited email; single use; re-activates a removed membership instead of duplicating; `410 invitation_used|revoked|expired`, `404 invitation_not_found`, `403 invitation_email_mismatch`, `409 already_member`). Email failure never loses the invitation (resend). Rate limiters: invitation-accept, team-invite, account-password.

**Audit readiness:** events in `App\Events\Access` (MemberInvited, InvitationResent, InvitationRevoked, InvitationAccepted, MemberRoleChanged, MemberRemoved, FarmSettingsUpdated) are dispatched after commit; no listeners/audit table yet (later phase). Removal keeps the row (`removed_at`, `removed_by_user_id`).

**Account:** `GET/PATCH /account` (name only; email read-only — email change needs re-verification, deferred; verification/onboarding state never writable), `PUT /account/password` (current password required; revokes tokens + other DB sessions; Google-only accounts get `409 password_not_set` → use forgot-password). No profile image/phone.

**Farm settings:** name only (`PATCH /farm`, `farm.update`). **Preferences:** only a notification-channel shell (`GET/PUT /settings/notifications`, `{channels:{in_app,email}}` stored as JSON on the membership, per user per farm; WhatsApp/engine later). **Deferred:** Units & Measurements (measurement phase), farm operations/locations (Phase 2 doc mentions them; the locked scope excludes them), Plan & Data, `tenants` table.

**Schema:** migration `2026_09_30_100000_...`: `farm_memberships` + `status` (default active), `removed_at`, `removed_by_user_id`, `notification_preferences` json; new `farm_invitations`. Reversible (rollback + re-migrate verified on `farm_management_test`). Applied to dev DB `farm_management` with plain `migrate`.

## Last Completed Task

Phase 2 — RBAC, Team & Access, account/farm settings, invitations.

## Endpoints (all under `/api/v1`)

Phase 0/1 unchanged (see above). Phase 2: `GET|PATCH /farm`, `GET /roles`, `GET /farm/members`, `GET|PATCH|DELETE /farm/members/{membership}`, `GET|POST /farm/invitations`, `POST /farm/invitations/{id}/resend`, `DELETE /farm/invitations/{id}`, `POST /invitations/accept`, `GET|PATCH /account`, `PUT /account/password`, `GET|PUT /settings/notifications`. Frontend guide: `docs/api/README.md` sections 5-8.

## Files Changed (Phase 2)

Created: app/Enums/{FarmRole,Permission,MembershipStatus}.php; app/Events/Access/*; app/Models/FarmInvitation.php; app/Support/Access/{FarmContext,NotificationPreferences}.php; app/Http/Middleware/{ResolveFarmContext,RequireFarmPermission}.php; app/Services/Team/{TeamService,InvitationService}.php; app/Services/Farm/FarmSettingsService.php; app/Notifications/FarmInvitationNotification.php; controllers Account/Farm/FarmInvitation/InvitationAcceptance/NotificationPreference/Role/TeamMember; requests Account/*, Farm/*, Team/*; resources Account/Farm/Invitation/Member; 1 migration; tests/Feature/Team/*.
Modified: FarmMembership, Farm, User, FarmPolicy, AuthRateLimiters, AuthFlowResponsesExtension (documents farm permission / 404 / farm-context 403), bootstrap/app.php (aliases), routes/api/v1.php, config/identity.php, .env.example (FRONTEND_URL, INVITATION_TTL_DAYS), docs/api/{README.md,openapi.json}, ApiDocumentationTest, OnboardingTest (role is now the FarmRole enum), WORKLOG.md.

## Tests

150 tests, 893 assertions, all passing (`php artisan test`, runs on `farm_management_test`); Phase 2 adds 67 tests (66 in tests/Feature/Team + 1 OpenAPI test; Phase 1 had 83); the Phase 1 auth suite is unchanged and green (one assertion adapted to the enum cast). Pint passes on app/database/routes/tests (config/ deliberately not touched).

## Packages Added

None.

## Open Issues / Blockers

- Carried over from Phase 1: `GOOGLE_CLIENT_ID` needed for real Google login; pre-existing Pint issues in bootstrap/providers.php + some config files (do NOT run pint on config/ blindly); production needs SESSION_DOMAIN/SECURE cookie, real MAIL_*, docs-exposure decision; Scramble pinned.
- Not built: ownership transfer, leaving a farm yourself, email change, profile image, Vet preset, units, farm operations/locations.
- Invitation email is synchronous (the token must not sit in the jobs table); slow SMTP slows the invite request.
- The invited email must equal the account's verified email (case-insensitive); there is no "accept with a different email" path by design.

---

## Next Task

Phase 3 — see `docs/implementations/10-IMPLEMENTATION-MASTER-PLAN.md` and its phase doc. Do not start until the user says so. Farm-management routes go in the `['app.access','farm.context']` group with `farm.permission:` middleware.

## Recommended Next Commit

`feat: add Phase 2 farm RBAC, team invitations, account and farm settings`

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
