# Farm Management API — Agent Worklog

> Shared handoff document for Claude Code, Codex, and other coding agents.
>
> Every agent MUST read this file before working and update it after
> completing meaningful work.
>
> Keep this document concise. It is a handoff document, not a diary.

---

## Current Status

**Current Phase:** Phase 5 — Measurements, Units & Dynamic Conversions

**Status:** Implemented; NOT committed (user reviews/commits). Phases 0-4 are committed (Phase 4 = `daefd8b`).

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

## Phase 3 Architecture (plans, subscription, entitlements)

**Locked rule:** RBAC ("may this USER act?") and entitlements ("does this FARM'S plan allow it?") are separate. No plan-name checks anywhere; no role checks in subscription code. Order: auth -> active membership -> RBAC -> entitlement -> business validation.

**Schema** (migration `2026_10_01_100000_create_subscription_tables`, reversible; data migration `2026_10_01_100100_provision_default_plans_and_backfill_subscriptions`): `plans` (uuid, slug unique, name, description, currency, `is_active`, `is_public`, `is_default`, sort_order), `plan_prices` (plan, interval monthly|annual, currency, `amount_minor` unsigned bigint = kobo; unique plan+interval+currency), `entitlements` (registry rows: key unique, type feature|limit), `plan_entitlements` (plan, entitlement, `enabled` for features, `limit_value` + `is_unlimited` for limits), `subscriptions` (ONE per farm, `farm_id` unique; plan_id, status, billing_interval, starts_at, current_period_start/end, cancel_at_period_end, cancelled_at, ends_at, provider + provider_reference groundwork), `subscription_events` (append-only history: type, from/to plan, from/to status, actor, occurred_at). New keys need no schema change. Flags: `is_active=false` => plan NOT honoured (safe fallback); `is_public=false` => hidden from the catalogue but still honoured; `is_default` = plan every new farm starts on.

**Registry:** `App\Enums\Feature` (`advanced_reports`, `data_export` — identifiers only, no feature built), `App\Enums\Limit` (`team_members`). To add one: enum case + label; a usage rule in `UsageResolver` (limits); plan values in the DB (seed/admin). Also `SubscriptionStatus` (active, past_due, cancelled, expired; no trialing), `SubscriptionEventType`, `BillingInterval`.

**Entitlement service (`App\Services\Subscription\EntitlementService`) is THE resolver:** `for($farm): EntitlementSet`, `allows($farm, Feature)`, `limit($farm, Limit): LimitValue`, `usage()`, `remaining()` (null = unlimited), `assertAllows()`, `assertCapacity($farm, Limit, $n=1)`, `forPlan($plan)`. Deny-by-default: the subscription's plan is honoured only if status is active/past_due AND `current_period_end` is null-or-future AND the plan is active; otherwise the DEFAULT plan (Free) applies and `subscriptionInactive=true`; no subscription row => default plan; no active default plan => nothing allowed, limits 0; missing/unknown/wrong-typed/malformed rows never grant. Not cached across calls (a plan change is immediate). Unlimited = `LimitValue::unlimited()` (`value=null, unlimited=true`; API `{limit:null, unlimited:true}`), never a big number. Route gate: middleware `entitlement:<feature_key>` (after `farm.context`). Errors: `App\Support\Entitlements\EntitlementException` (extends ApiHttpException, which now supports optional `details`, rendered as `details` in the error envelope): `403 feature_not_available`, `403 subscription_inactive`, `409 plan_limit_reached` (details: entitlement_key, limit, usage, remaining).

**Default subscription:** `Farm::created` hook -> `SubscriptionService::startDefault()` (same transaction as farm creation, so onboarding, factories and any future creation path are covered; idempotent; throws if no active default plan exists). The data migration backfills existing farms. Free has no period (`current_period_end` null).

**Lifecycle (`SubscriptionService`):** `changePlan($farm, $plan, ?interval, ?actor)` (internal only — for the future webhook processor/admin tooling; a paid plan starts a period of the interval from now, the default plan clears the period), `cancel` (paid only; sets `cancel_at_period_end`, access continues), `resume`, `closeLapsed()` (command `subscriptions:close-lapsed`, scheduled hourly in routes/console.php: period ended => `cancelled` if cancellation was scheduled, else `expired`; `ends_at` = period end). Every mutation locks the subscription row and appends a `subscription_events` row, so history survives plan_id changes. No separate Laravel event classes (the event rows are the audit hook; add classes when the audit module needs them).

**Team-limit integration:** `InvitationService::invite` calls `assertCapacity(TeamMembers)` inside the existing per-farm row lock (concurrency-safe: two simultaneous invites cannot both take the last seat) BEFORE the duplicate/already-member business checks. `resend` of an EXPIRED invitation re-checks capacity (its seat was released; resend now also takes the farm lock). Accepting a pending invitation is net-zero, so it is never blocked. **Usage rule (`UsageResolver`):** active memberships (Owner included) + pending invitations (unaccepted, unrevoked, unexpired). Removed members and expired/revoked invitations do not count. Downgrade: over-limit state is kept and readable (`exceeded: true`, `remaining: 0`); only growth is blocked.

**Permissions:** `subscription.view` (owner, manager, finance), `subscription.manage` (owner only). `GET /subscription/entitlements` uses `farm.view` so every member can render gated UI.

**Seed data (PROVISIONAL, configurable; final prices/limits are an open product decision):** `PlanSeeder` is INSERT-ONLY (skips existing slugs, never overwrites admin edits) and runs from the data migration, so local/test/prod DBs get plans on `migrate` and tests need no manual step. Free (default, ₦0, team_members 3, no paid features); Farm Pro `farm-pro` (₦3,000/mo = 300000 kobo, ₦30,000/yr = 3000000, advanced_reports on, team_members 10); Farm Business `farm-business` (₦7,500/mo = 750000, ₦75,000/yr = 7500000, advanced_reports + data_export, team_members unlimited).

**Billing provider: DEFERRED.** No provider chosen (37-OPEN-DECISIONS). NOT built: `POST /subscription/checkout`, `POST /billing/webhooks/{provider}`, `billing_transactions`, any Paystack/Flutterwave code, mock payment endpoints. `subscriptions.provider/provider_reference` are the only groundwork. When a provider is chosen: interface + adapter, `billing_transactions` + stored provider event ids (idempotent, signature-verified, server-side verification, out-of-order safe), and call `SubscriptionService::changePlan` on verified payment only. Platform Admin plan editing is also not built (plans are plain editable rows).

## Phase 4 Architecture (agricultural master data)

**Status:** implemented; NOT committed. Master data = what the system UNDERSTANDS, never what happened on a farm. No operational tables exist.

**Tables** (migrations `2026_10_02_100000_create_master_data_tables` reversible; `2026_10_02_100100_provision_agricultural_master_data` runs `MasterDataSeeder`): `operation_types` (code, name, `category` livestock|aquaculture|crop, `tracking_model` population|planting_units), `species` (-> operation_type), `crop_types` (-> operation_type), `capabilities` (registry, mirrors `App\Enums\Capability`), `species_capabilities` (`enabled`, `reference_config` json), `reference_values` (`list`+`code`: planting_material_type / planting_unit_type), `breeds` (species_id, nullable farm_id, code, name, normalized_name, is_active), `crop_varieties` (same shape, crop_type_id), `farm_operations` (farm_id, operation_type_id). Deviation from the ERD: ONE `breeds` table (system row = farm_id NULL, custom = farm_id set) instead of `breeds`+`custom_breeds`, so future records need a single breed FK. Uniqueness: generated column `scope = coalesce(farm_id,'system')`, unique (parent, scope, normalized_name) and (parent, code).

**System vs farm:** system rows are shared, never copied per farm. Farms only ADD custom breeds/varieties (no overrides of system rows: not needed yet, so not built; an overlay table can be added later if a farm must rename/hide a system item). `App\Models\Concerns\FarmScopedMasterRecord` holds the only "system + this farm" scope (`visibleTo`, `customOf`, `system`, `active`) and name normalization. Reads live in `MasterDataCatalogue`, writes in `CustomMasterDataService` (farm from `FarmContext`, name duplicate check vs system + own farm -> `409 duplicate_name` with details, unique-index race also -> 409, deactivate/reactivate instead of delete, dispatches `App\Events\MasterData\CustomMasterDataChanged` created|updated|deactivated|reactivated for the future audit module). Other farm's/system ids on PATCH -> 404. Request bodies reject `farm_id`, `code`, parent id (`missing` rule).

**Taxonomy:** operations (category): poultry, cattle, goat, sheep, pig, rabbit (livestock), fishery (aquaculture), crops (crop). Species (operation): chicken (poultry), cattle, goat, sheep, pig, rabbit, fish (fishery). Crops (all under `crops`): maize, cassava, yam, vegetables, fruits. Only ONE species per operation is seeded (docs confirm only "chicken" and generic "fish"); other poultry species, fish species etc. are an open catalogue decision (rows only, no code change).

**Seeded capabilities:** all 11 registry codes. Every species: supports_group_tracking, supports_mortality, supports_feed_records, supports_live_weight. chicken + produces_eggs, supports_incubation (`incubation_days: 21`), supports_breeding. cattle + supports_pregnancy (`gestation_days: 283`), supports_breeding. goat + supports_pregnancy (NO gestation value), supports_breeding. fish + supports_harvest. NOT seeded (unconfirmed): produces_milk anywhere, individual tracking, pregnancy for sheep/pig/rabbit ("where configured"), any other duration. Reference values are biological reference defaults only (distinct from farm target, configuration, actual outcome). `reference_config` keys are validated by `Capability::configRules()` via `SpeciesCapabilityService::set()` (the only writer); unknown keys/other types rejected.
**Planting lists:** material types seed, seedling, stem_cutting, tuber, sucker, other; unit types heap, hole, stand (separate lists; planting units != material quantity). No per-crop defaults/farm-configured units yet. **Breeds/varieties seeded: none** (unconfirmed).

**Seeder** is insert-only keyed by code (never overwrites edits), run by the data migration so dev/test DBs need no manual step; safe to rerun (`db:seed --class=MasterDataSeeder`).

**Farm operations:** optional, post-onboarding, never blocks. No rows = unconfigured = everything `available`; once selected, `available=true` filters narrow operations/species/crops. `GET|PUT /farm/operations`.

**Permissions:** `master_data.view` (owner, manager, farm_worker, finance), `master_data.manage` (owner, manager; worker/finance denied per matrix "Farm config: No" — a worker-facing "+ add custom breed" is an open product question). Farm operations: `farm.view` / `farm.update`. Not plan-gated. Rate limiter `master-data-write` (60/h/user) on POST/PATCH.

**Endpoints:** `GET /master/farm-operations`, `/master/species`, `/master/species/{id}/capabilities`, `/master/species/{id}/breeds`, `/master/crops`, `/master/crops/{id}/varieties`, `/master/planting-reference`; `GET|POST /custom-breeds`, `PATCH /custom-breeds/{id}`; `GET|POST /custom-varieties`, `PATCH /custom-varieties/{id}`; `GET|PUT /farm/operations`. No DELETE (deactivate). Filters: `operation` (code), `category`, `available`, `include_inactive`. Frontend guide `docs/api/README.md` sections 13-17.

**Deferred:** `/master/record-types` + schema versioning (depends on Phase 5 units / Phase 8 records), `/master/task-categories`, `/master/units`, per-crop planting defaults, farm-configured planting unit types, system overrides, Platform Admin editing of master data, milk capability seeding, more species/breeds/varieties.

**Ops notes:** the OpenAPI export is memory hungry: use `php -d memory_limit=1G artisan test` / `scramble:export`; `ApiDocumentationTest` now generates the spec once per run. `AuthFlowResponsesExtension` documents `401` on `app.access` routes.

**Phase 4 closing verification:** (1) Migration reversibility exercised on `farm_management_test` only: rollback of `provision_agricultural_master_data` (its `down()` is intentionally a no-op; the tables are dropped by the next step), rollback of `create_master_data_tables` (all 9 tables removed, no FK-order errors), then `migrate` re-applied both and the seed returned identically (7 species, 36 species-capability rows, 9 reference values). (2) Seed catalogue audited against the source docs: goat pregnancy is sourced by `02-DOMAIN-BEHAVIOUR-MATRIX.md` ("Cattle/goat example" pregnancy capability; farm-setup row Cattle/Goat/Sheep/Pig/Rabbit "pregnancy/birth workflow where configured"). Goat keeps `supports_pregnancy` with no gestation value. Only sourced reference values are seeded: chicken incubation 21 d, cattle gestation 283 d. `supports_breeding` (chicken/cattle/goat) is derived from the matrix "Breeding" rows, which are defined over incubation-/pregnancy-capable species. No seed change was needed.

## Phase 5 Architecture (measurements, units, conversions)

**Status:** implemented; NOT committed. Measurement INFRASTRUCTURE only - no operational record uses it yet (no locations, batches, inventory, feed, eggs, harvest...). Later modules must call `QuantityNormalizer` / `MeasurementConverter`; nobody else multiplies quantities by unit factors.

**Tables** (migrations `2026_10_03_100000_create_measurement_tables` reversible; `2026_10_03_100100_provision_standard_measurement_data` runs `MeasurementSeeder`, insert-only by code): `measurement_dimensions` (code, name, `supports_preference`), `units` (dimension, code, name, symbol, `family`, `is_canonical`, `conversion_strategy` linear|fahrenheit|none, `to_canonical_factor` decimal(30,12), `decimal_places`, `integer_only`, `is_system`, `is_active`), `farm_unit_preferences` (farm+dimension unique -> unit), `measurement_contexts` (farm_id, name, normalized_name, is_active; unique farm+normalized_name; added by `2026_10_03_100200_add_measurement_contexts`), `package_conversions` (farm_id, `context_type`, `context_id` uuid, package_unit, target_unit, `quantity_per_package` decimal(18,6), `version`, `is_active`; unique farm+context_type+context_id+package unit; NO label/key columns).

**Dimensions (6):** `weight` (the docs call it "weight"; the prompt said "mass"), `volume`, `area`, `count`, `temperature`, `package`. Length/time/currency deliberately NOT seeded (unconfirmed; currency is integer minor units elsewhere).
**Units seeded (23), canonical = factor 1:** weight: mg 0.001, **g**, kg 1000, tonne 1e6, lb 453.59237 (exact). volume: **ml**, cl 10, l 1000. area: **sq_m** (m²), hectare 1e4, acre 4046.8564224 (exact, international). temperature: **celsius**, fahrenheit (strategy `fahrenheit`). count (each its own family AND canonical, integer_only): piece, egg, head, planting_unit. package (no family, no factor): bag, sack, crate, tray, carton, bottle. Codes are the identity (`l`, `sq_m`); symbols/names are display only.

**Standard conversion (`App\Services\Measurement\MeasurementConverter`, pure, no DB):** units convert only within the same non-null `family` via the canonical unit. Everything else = `422 incompatible_units`. Count families never mix (50 heads != 50 eggs; planting units never imply material quantity). Temperature = known strategy `fahrenheit` ((F-32)*5/9); no formula language. System units are protected in the model (redefining code/family/factor/strategy or deleting throws `LogicException`; only name/symbol/`is_active` are editable). No farm/custom physical units exist (packages are conversions, not units).

**Exact arithmetic (`App\Support\Measurement\Decimal`, bcmath):** all values are numeric STRINGS; no floats. Entered quantities: <=12 integer digits, <=6 decimals (JSON floats accepted only via their shortest text); calculation scale 18; results/normalized rounded half away from zero to 6 dp; normalized quantities allow 18 integer digits (storage contract for later columns: `decimal(24,6)`). `ext-bcmath` added to composer.json `require` (lock content-hash refreshed with `composer update --lock`; no package installed).

**Packages and contexts (context abstraction before inventory exists):** `bag`/`crate` etc. have NO universal size. A `package_conversions` row says "in context C, 1 package = N target unit". Context = `(context_type, context_id)`; the id is ALWAYS a real, validated entity, never display text: `crop_type` (id = an ACTIVE `crop_types.id`, e.g. Maize) or `custom` (id = `measurement_contexts.id`, a farm-owned, farm-scoped, renameable context such as Feed Grower Mash / Eggs, created via `POST /settings/measurement-contexts`). `PackageConversionService::resolveContext()` is the single gate (create AND `QuantityNormalizer::normalize`): unknown/malformed/wrong-type/inactive/another farm's id -> `422` on `context_id` / `context.id`. Labels are read from the entity (crop name / context name), never stored on the conversion; snapshots store `{type, id, label-at-the-time}`. Phase 9 adds `inventory_item` (id = item UUID) as a new `ConversionContextType` case + a branch in `resolveContext` with NO schema change; existing custom contexts do NOT automatically become inventory items (that would need a deliberate link/migration). Correction history: the first Phase 5 cut keyed custom contexts by `Str::slug(client text)` with the label copied per row; replaced before closing the phase (migration backfills legacy rows: one context per farm+key). Feed bag 25 kg and Maize bag 50 kg coexist as two rows. Contexts are always required for package units: missing -> `conversion_context_required` (or `ambiguous_conversion` if >=2 candidates, `details.candidates`); never guessed. Every lookup is filtered by the FarmContext farm.

**Compound quantities:** `QuantityNormalizer::normalize($farm, [['quantity','unit'],...], ?ConversionContext, ?resultUnit, ?requiredDimensions)` -> `NormalizationResult{entered, normalized (canonical), total (result unit), snapshot}`. 3 crates + 14 pieces (1 crate=30 pieces) -> 104 piece. 12 bags + 18 kg with bag=50 kg -> 618 kg (normalized 618000 g). Parts must share a family; units may not repeat; temperatures can't be summed; a fractional result in an integer-only unit -> `invalid_quantity` (`reason: fraction_not_allowed`). Domain fields declare accepted dimensions through `requiredDimensions` (water: `['volume']`, dose: `['weight','volume']`) -> `unit_dimension_mismatch`.

**History strategy (chosen: snapshot the conversion used + in-place versioning):** `NormalizationResult::snapshot` (schema 1) embeds every entered part with its full UnitSpec (code, dimension, family, strategy, factor, integer_only) and every PackageDefinition used (conversion id, version, context, per_package, target spec). `MeasurementConverter::replay($snapshot)` recomputes the numbers from the snapshot alone (tested after the conversion row was edited, deactivated and the unit relabelled). Later operational tables should store entered parts + normalized quantity/unit + this snapshot JSON. `package_conversions.version` bumps on quantity/target/active changes (label-only edits don't) and is recorded in snapshots; rows are edited in place, not duplicated.

**Preferences:** `farm_unit_preferences` is farm-wide (per ERD), only for dimensions with `supports_preference` (weight, volume, area, temperature). Defaults (`UnitPreferenceService::DEFAULTS`): kg, l, hectare, celsius; `null` resets. Display/entry defaults only; never affect normalization.

**Permissions:** `measurement.view` (owner, manager, farm_worker, finance - selectors, package conversions, preview), `measurement.manage` (owner, manager - preferences and package conversions). Not plan-gated. Rate limiters `measurement-write` (60/h/user) and `measurement-preview` (120/min/user).

**Endpoints:** `GET /master/measurement-dimensions`, `GET /master/units?dimension=` (dimension REQUIRED; optional `family`, `include_inactive`; there is deliberately no "all units" listing), `GET|PUT /settings/units`, `GET|POST /settings/measurement-contexts`, `PATCH /settings/measurement-contexts/{id}` (rename / deactivate; no DELETE), `GET|POST /settings/package-conversions`, `PATCH /settings/package-conversions/{id}` (quantity/target/is_active only; no DELETE - deactivate), `POST /measurements/normalize` (preview; same service as future modules). Docs: `docs/api/README.md` section 18.

**Errors (`App\Support\Measurement\MeasurementException`, all 422 unless noted):** incompatible_units, conversion_context_required, ambiguous_conversion, conversion_not_configured (`details.inactive`), invalid_quantity, invalid_conversion_ratio, unknown_unit, unit_not_selectable, unit_dimension_mismatch, conversion_exists (409), measurement_context_exists (409). Event `App\Events\Measurement\PackageConversionChanged` (created|updated|deactivated|reactivated) dispatched for a future audit module.

**Deferred measurement decisions:** length/time/dose dimensions; farm custom units (intentionally none); an `inventory_item` context and item-level conversions (Phase 9; any link from a farm's custom contexts to items must be built deliberately); per-crop/species default packaging; platform-admin unit editing UI; per-USER unit preferences (farm-wide per ERD); `piece` vs `egg` (kept as separate count families: "3 crates + 14 pieces" uses a crate->piece definition; an eggs context may equally target `egg`); whether a `bird` count unit is needed (bird = head for now); Phase 4 planting unit types (heap/hole/stand) stay reference values, `planting_unit` is only a generic count unit.
## Last Completed Task

Phase 5 — Measurements, units & dynamic conversions.

## Endpoints (all under `/api/v1`)

Phase 5: see the Phase 5 Architecture section (frontend guide section 18). Phase 4: master data (guide sections 13-17). Phase 0/1 unchanged (see above). Phase 3: `GET /public/plans` (public), `GET /subscription`, `GET /subscription/entitlements`, `GET /subscription/usage`, `POST /subscription/cancel`, `POST /subscription/resume`; `POST /farm/invitations` can now return `409 plan_limit_reached`. Frontend guide: `docs/api/README.md` sections 9-12. Phase 2: `GET|PATCH /farm`, `GET /roles`, `GET /farm/members`, `GET|PATCH|DELETE /farm/members/{membership}`, `GET|POST /farm/invitations`, `POST /farm/invitations/{id}/resend`, `DELETE /farm/invitations/{id}`, `POST /invitations/accept`, `GET|PATCH /account`, `PUT /account/password`, `GET|PUT /settings/notifications`. Frontend guide: `docs/api/README.md` sections 5-8.

## Files Changed (Phase 3)

Created: app/Enums/{Feature,Limit,SubscriptionStatus,SubscriptionEventType,BillingInterval}.php; app/Models/{Plan,PlanPrice,Entitlement,PlanEntitlement,Subscription,SubscriptionEvent}.php; app/Services/Subscription/{EntitlementService,SubscriptionService,UsageResolver}.php; app/Support/Entitlements/{LimitValue,EntitlementSet,EntitlementException}.php; app/Http/Middleware/RequireEntitlement.php; controllers Plan/Subscription; resources Plan/Subscription; app/Console/Commands/CloseLapsedSubscriptions.php; database/seeders/PlanSeeder.php; 2 migrations; tests/Feature/Subscription/*.
Modified: Farm (created hook + subscription relation), FarmRole/Permission, InvitationService (limit check), ApiHttpException/ApiExceptionRenderer/ApiErrorResponse (`details`), FarmInvitationController docs, bootstrap/app.php (`entitlement` alias), routes/api/v1.php, routes/console.php, docs/api/{README.md,openapi.json}, RolePermissionTest/InvitationTest/TeamTestCase/ApiDocumentationTest (adapted: new permissions, `onPlan()` helper, Free team limit of 3), WORKLOG.md.

## Files Changed (Phase 2)

Created: app/Enums/{FarmRole,Permission,MembershipStatus}.php; app/Events/Access/*; app/Models/FarmInvitation.php; app/Support/Access/{FarmContext,NotificationPreferences}.php; app/Http/Middleware/{ResolveFarmContext,RequireFarmPermission}.php; app/Services/Team/{TeamService,InvitationService}.php; app/Services/Farm/FarmSettingsService.php; app/Notifications/FarmInvitationNotification.php; controllers Account/Farm/FarmInvitation/InvitationAcceptance/NotificationPreference/Role/TeamMember; requests Account/*, Farm/*, Team/*; resources Account/Farm/Invitation/Member; 1 migration; tests/Feature/Team/*.
Modified: FarmMembership, Farm, User, FarmPolicy, AuthRateLimiters, AuthFlowResponsesExtension (documents farm permission / 404 / farm-context 403), bootstrap/app.php (aliases), routes/api/v1.php, config/identity.php, .env.example (FRONTEND_URL, INVITATION_TTL_DAYS), docs/api/{README.md,openapi.json}, ApiDocumentationTest, OnboardingTest (role is now the FarmRole enum), WORKLOG.md.

## Tests

**Phase 5 latest (after the context-identity correction): 303 tests, 2030 assertions, all passing** (`php -d memory_limit=1G artisan test` on `farm_management_test`; Pint clean on app/database/routes/tests). Phase 5 added 70 tests (61 originally + 9 for stable context identity: id vs name, rename keeps conversions/snapshots, crop validation, type/id mismatch, deactivation, farm isolation of contexts, context CRUD/RBAC): StandardConversionTest 19 (units/seed/protection/exact conversions/temperature/count semantics/quantity validation/rounding), CompoundQuantityTest 20 (104 pieces, 618 kg, contexts, farm isolation, ambiguity, snapshot replay after config edits), MeasurementApiTest 30 (dimension-filtered selectors, RBAC, package conversion CRUD/validation/versioning/409, isolation, preview endpoint, preferences), OpenAPI 1; RolePermissionTest expectations extended. Migration down()/up() exercised on `farm_management_test` (rollback of both Phase 5 migrations dropped the 4 tables, re-apply restored 23 units / 6 dimensions); the contexts migration's down()/backfill/up() was also exercised on `farm_management_test` with legacy-shaped rows (2 custom rows + 1 crop row -> 1 context, ids matched, down() restored the keys); dev DB `farm_management` got the migrations via plain `php artisan migrate`; `docs/api/openapi.json` regenerated.

Previous (Phase 4): 233 tests, 1453 assertions, all passing** (`php -d memory_limit=1G artisan test`, on `farm_management_test`; Pint clean on app/database/routes/tests). Phase 4 added 40 tests (SystemMasterData 8, MasterDataApi 15, CustomMasterData 16, OpenAPI 1); RolePermissionTest expectations were extended for the new permissions. Dev DB `farm_management` got the 2 new migrations via plain `php artisan migrate`; `docs/api/openapi.json` regenerated.

Previous (Phase 3): 193 tests, 1157 assertions, all passing (`php artisan test`, runs on `farm_management_test`). Phase 3 adds 43 (PlanCatalogue 7, EntitlementService 14, TeamLimit 9, SubscriptionApi 12, OpenAPI 1); existing Phase 0-2 tests are unchanged except 3 adaptations (new permissions in role lists; one invitation test moved to Farm Pro because Free allows 3 team members). Migrations were rolled back and re-applied on the test DB; plain `php artisan migrate` applied to dev DB `farm_management` (0 farms there, so the backfill is covered by a test). The simultaneous-request race is guarded by the farm row lock but not exercised by a parallel test (PHPUnit is single-process). Pint clean on app/database/routes/tests.

Earlier: Phase 2 added 67 tests (66 in tests/Feature/Team + 1 OpenAPI test; Phase 1 had 83); the Phase 1 auth suite is unchanged and green (one assertion adapted to the enum cast). Pint passes on app/database/routes/tests (config/ deliberately not touched).

## Packages Added

None installed. `ext-bcmath` declared in composer.json `require` (PHP extension used for exact decimal arithmetic; enabled in this environment); composer.lock content-hash refreshed only.

## Open Issues / Blockers

- Carried over from Phase 1: `GOOGLE_CLIENT_ID` needed for real Google login; pre-existing Pint issues in bootstrap/providers.php + some config files (do NOT run pint on config/ blindly); production needs SESSION_DOMAIN/SECURE cookie, real MAIL_*, docs-exposure decision; Scramble pinned.
- Phase 3 unresolved: final prices/limits/feature split (seed is provisional); payment provider + checkout/webhooks/billing_transactions; user-initiated plan change (none until payments exist, so cancel/resume are only reachable for farms moved to a paid plan by `changePlan`); Platform Admin plan management; `past_due` is honoured until the period ends (no separate grace policy); more `Feature`/`Limit` keys are added as later phases need them (farm count, storage, ...).
- Not built: ownership transfer, leaving a farm yourself, email change, profile image, Vet preset, locations. Production PHP must have the bcmath extension (declared in composer.json).
- Invitation email is synchronous (the token must not sit in the jobs table); slow SMTP slows the invite request.
- The invited email must equal the account's verified email (case-insensitive); there is no "accept with a different email" path by design.

---

## Next Task

Phase 6 (see `docs/implementations/10-IMPLEMENTATION-MASTER-PLAN.md`). Do not start until the user says so. Use `QuantityNormalizer` for every quantity a later phase stores (entered parts + normalized quantity/unit + snapshot JSON, `decimal(24,6)` columns). Farm-management routes go in the `['app.access','farm.context']` group with `farm.permission:` and, for plan-gated features, `entitlement:<key>` middleware (or `EntitlementService::assertCapacity` inside the locking transaction for capacity limits).

## Recommended Next Commit

`feat: add Phase 5 measurements, standard units, exact decimal conversions and farm package conversions`

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
