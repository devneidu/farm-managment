# API Documentation

OpenAPI 3.1 docs are generated from the Laravel code by [dedoc/scramble](https://scramble.dedoc.co)
(routes, FormRequests, return types and PHPDoc on controllers), so they stay coupled to the implementation.

| What | Where |
|---|---|
| Browser docs (Stoplight Elements) | `/docs/api` (e.g. `http://localhost/docs/api`) |
| Live OpenAPI JSON | `/docs/api.json` |
| Committed spec for the frontend developer | `docs/api/openapi.json` |

Docs routes are available only when `APP_ENV=local` (Scramble's default gate). To expose them elsewhere,
define the `viewApiDocs` gate in a service provider.

## Export / refresh the spec

```bash
php artisan scramble:export --path=docs/api/openapi.json
```

Re-run and commit `docs/api/openapi.json` whenever an endpoint changes.

## Postman

Postman → Import → select `docs/api/openapi.json` (or paste the `/docs/api.json` URL). The server base URL is
`{APP_URL}/api/v1`; paths in the spec are relative to it (e.g. `/health`).

## Response conventions

Success (`2xx`): `{"data": ..., "meta": {}, "message": null}`

Errors: `{"message": "...", "code": "...", "request_id": "...", "errors": {...}}`
(`errors` only for `422`). Every response carries an `X-Request-Id` header, echoed as `request_id` in errors.

| Status | `code` |
|---|---|
| 401 | `unauthenticated` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 405 | `method_not_allowed` |
| 409 | `conflict` |
| 422 | `validation_failed` |
| 429 | `too_many_requests` |
| 500 | `server_error` |

## Documenting an endpoint

Use PHPDoc on the controller method (first line = summary), FormRequests for the request body, and
`@response` / `@unauthenticated` tags where inference is not enough. See `HealthController` for the pattern.

---

# Frontend authentication guide (Phase 1)

Authentication is **Laravel Sanctum first-party SPA cookie authentication**: the browser holds an
HttpOnly session cookie; there are no bearer tokens in the React app. Every endpoint below is documented
in `docs/api/openapi.json` (auth requirement, request/response schemas, error codes).

## 0. One-time setup

| Item | Value |
|---|---|
| API base URL | `VITE_API_URL` (dev: `http://localhost:8000/api/v1`) |
| Frontend origin (dev) | `http://localhost:5173` or `http://localhost:3000` |
| Every request | `withCredentials: true` (fetch: `credentials: 'include'`), `Accept: application/json` |
| State-changing requests (POST/PUT/PATCH/DELETE) | header `X-XSRF-TOKEN` = value of the `XSRF-TOKEN` cookie (URL-decoded). Axios does this automatically with `withCredentials: true` and `withXSRFToken: true`. |

Backend `.env` must list your frontend: `CORS_ALLOWED_ORIGINS` (with scheme) and `SANCTUM_STATEFUL_DOMAINS` (host:port,
no scheme); `CORS_SUPPORTS_CREDENTIALS=true`. Requests **must** come from a listed origin (the browser sends `Origin`);
otherwise the API refuses to start a session (`400 stateful_request_required`). Use the API host and the frontend host
under the same site (e.g. both `localhost` in dev; `app.example.com` + `api.example.com` in production with
`SESSION_DOMAIN=.example.com`, `SESSION_SECURE_COOKIE=true`).

```js
const api = axios.create({ baseURL: import.meta.env.VITE_API_URL, withCredentials: true, withXSRFToken: true,
                           headers: { Accept: 'application/json' } });
await api.get('/auth/csrf-cookie');   // 1. once at app start (and again after a 419)
```

## 1. Auth state - the only thing the router needs

Every auth endpoint (register, login, google, email/verify, onboarding/farm, `GET /auth/me`) returns:

```json
{ "data": {
    "authenticated": true,
    "email_verified": false,
    "onboarded": false,
    "has_active_farm": false,
    "next_action": "verify_email",
    "user": { "id": "uuid", "email": "a@b.com", "name": null, "email_verified_at": null, "has_password": true, "providers": [] },
    "farm": null },
  "meta": {}, "message": null }
```

`next_action` -> screen: `verify_email` -> `/verify-email`; `complete_farm_setup` -> `/onboarding/farm`; `no_active_farm` -> a "you have no
farm" screen (see below); `none` -> app.

These are four distinct states, never derive one from another: email verified (`email_verified`), onboarding completed
(`onboarded`, set once and never cleared), active farm membership (`has_active_farm`; `farm` is the default active farm
or `null`). A user can be authenticated + verified + onboarded with `has_active_farm: false` (removed from their last
farm): `next_action = no_active_farm`, they are NOT sent back to farm setup (`POST /onboarding/farm` is `409
already_onboarded`), and farm endpoints answer `403 no_active_farm`. Accepting a new invitation gets them back in.
On app load call `GET /auth/me`: `401` = show login. Do not derive routing from anything else. The backend also enforces
this: unverified users get `403 email_verification_required`, un-onboarded users `403 onboarding_required` on every
farm-management endpoint.

## 2. Flows

**Email signup:** `GET /auth/csrf-cookie` -> `POST /auth/register {email, password, password_confirmation}` (`201`, logged in,
`next_action=verify_email`, code emailed) -> `POST /auth/email/verify {code}` (6 digits) -> `next_action=complete_farm_setup`
-> `POST /onboarding/farm {name}` (`201`, `next_action=none`). Resend: `POST /auth/email/resend` (60 s cooldown -> `429` with
`Retry-After`). Codes expire after 10 minutes, are single use, and die after 5 wrong attempts (request a new one).

**Returning user:** `POST /auth/login {email, password}` -> read `next_action`. Wrong email or password: `401 invalid_credentials`
(same for both). An **unverified** account is logged in but restricted: `next_action=verify_email` (use `/auth/email/resend`
if the old code is gone).

**Google:** get an ID token with Google Identity Services (`credential` from the `google.accounts.id` callback / One Tap /
button) and `POST /auth/google {credential}`. `201` = new account, `200` = existing. The email is treated as verified and
no OTP is sent; `next_action` is `complete_farm_setup` until a farm exists. An existing email/password account with the
same email is linked (not duplicated). Needs `GOOGLE_CLIENT_ID` in the backend `.env` (same OAuth Web client id the
frontend uses); otherwise `503 google_unavailable`.

**Password reset:** `POST /auth/password/forgot {email}` (always `200`, generic message) -> `POST /auth/password/verify-otp
{email, code}` -> `{reset_token, expires_in}` -> `POST /auth/password/reset {email, reset_token, password,
password_confirmation}` -> go to login. The reset does **not** log the user in and signs them out everywhere (all sessions
and API tokens are revoked). `reset_token` is single use and expires after 15 minutes.

**Logout:** `POST /auth/logout` ends the current browser session only.

## 3. Errors

Standard envelope `{message, code, request_id, errors?}`. Codes you should branch on: `unauthenticated` (401),
`invalid_credentials` (401), `email_verification_required` / `onboarding_required` / `account_suspended` (403),
`already_verified` / `already_onboarded` (409), `validation_failed` (422, `errors.<field>[]`), `session_expired` (419 - refetch
`/auth/csrf-cookie` and retry once), `too_many_requests` (429, honour `Retry-After`), `google_unavailable` (503).
OTP failures are deliberately uniform: `422` with `errors.code` = "The code is invalid or has expired."

Password rules: 8-72 characters, at least one letter and one number, `password_confirmation` must match.

## 4. Local email

`MAIL_MAILER=log` (default in `.env.example`) writes emails, including the OTP, to `storage/logs/laravel.log`. For a mail
UI use Mailpit (`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`). A production provider (Postmark/Resend/SES) is
selected by `MAIL_*` env only.

---

# Phase 2 - Farm, team & access, account settings

All routes below are under `/api/v1`, need the session cookie (+ `X-XSRF-TOKEN` on writes) and, except `/account/*` and
`/invitations/accept`, a verified + onboarded user (`app.access`). The OpenAPI spec (`docs/api/openapi.json`) lists the
required farm permission in each operation's description.

## 5. Which farm am I acting on?

Farm-scoped endpoints never take a farm id in the URL. The backend resolves it from **your own active memberships**:
your oldest active membership by default, or the farm named in an optional `X-Farm-Id: <farm uuid>` header. Sending a
farm you do not actively belong to is `403 farm_access_denied`; a user with no active membership is `403 no_active_farm`
(or `onboarding_required` if they have never set up a farm). Ids of other farms' members/invitations are `404`.

## 6. Roles and permissions

Roles: `owner`, `manager`, `farm_worker`, `finance` (labels: Owner, Manager, Farm Worker, Finance). `GET /farm` returns
`membership.role` and `membership.permissions` - use those to show/hide UI (the backend enforces them regardless).
`GET /roles` returns the presets and, per role, whether the caller may assign it.

| Permission | owner | manager | farm_worker | finance |
|---|:-:|:-:|:-:|:-:|
| `farm.view` | x | x | x | x |
| `farm.update` | x | x | | |
| `team.view` / `team.invite` / `team.update_role` / `team.remove` | x | x | | |
| `inventory.view` | x | x | x | x |
| `inventory.use` | x | x | x | |
| `inventory.manage`, `inventory.adjust` | x | x | | |
| `livestock.batch.create` (reserved) | x | x | | |
| `finance.expense.create` (reserved) | x | | | x |
| `subscription.view` | x | x | | x |
| `subscription.manage` | x | | | |

Team rules: Owner may invite/assign manager, farm_worker, finance. Manager may only invite/assign/manage farm_worker and
finance. **Nobody can assign `owner`** (`422 ownership_transfer_unsupported`), change their own role, or demote/remove the
last owner (`409 last_owner`). Insufficient hierarchy is `403 insufficient_role`; a missing permission is `403 forbidden`.

## 7. Endpoints

| Method & path | Permission | Notes |
|---|---|---|
| `GET /farm` | farm.view | farm + my role/permissions |
| `PATCH /farm` `{name}` | farm.update | only the name is editable |
| `GET /roles` | team.view | role presets |
| `GET /farm/members`, `GET /farm/members/{membership}` | team.view | `{membership}` = membership id (not user id) |
| `PATCH /farm/members/{membership}` `{role}` | team.update_role | |
| `DELETE /farm/members/{membership}` | team.remove | member loses access immediately; can be re-invited |
| `GET /farm/invitations` | team.view | pending/expired only; the token is never returned |
| `POST /farm/invitations` `{email, role}` | team.invite | `201`; `409 already_member` / `invitation_already_pending` |
| `POST /farm/invitations/{id}/resend`, `DELETE /farm/invitations/{id}` | team.invite | resend = new link + new expiry |
| `POST /invitations/accept` `{token}` | verified user | see below |
| `GET/PATCH /account`, `PUT /account/password` | verified user | name only; email is read-only |
| `GET/PUT /settings/notifications` `{channels:{in_app,email}}` | any member | per user per farm; shell only |

## 8. Invitation flow

Owner/manager `POST /farm/invitations` -> the invitee gets an email with `{FRONTEND_URL}/invitations/accept?token=...`
(valid 7 days, single use). The frontend keeps the token, sends the person to sign in / register **with the invited
email** (email OTP or Google), then calls `POST /invitations/accept {token}`. Response = auth state (`next_action: none`,
a brand-new user skips farm setup) plus `meta.accepted_farm_id`. Errors: `403 invitation_email_mismatch`,
`404 invitation_not_found`, `409 already_member`, `410 invitation_used | invitation_revoked | invitation_expired`.
A user who already has a farm keeps it as their default; send `X-Farm-Id` to work on the new one.
Removing a user's last membership does NOT clear `onboarded`; they get `next_action = no_active_farm`. Accepting an
invitation marks a never-onboarded verified user as `onboarded` (they enter through an existing farm instead of creating
one); it never creates a farm and never duplicates a membership.

---

# Phase 3 - Plans, subscription & entitlements

## 9. Two separate systems

- **RBAC** (Phase 2): may THIS USER do this in the farm? -> `403 forbidden`.
- **Entitlements** (Phase 3): does THIS FARM'S plan allow the feature/capacity? -> the codes below.
An action needs both. A paid plan never grants a role a permission it lacks; a permission never bypasses the plan.
Check order on the backend: authenticated -> active membership -> RBAC -> plan entitlement -> business validation.
Never hard-code plan names, prices or limits in the frontend: render them from the API.

## 10. Endpoints

| Endpoint | Auth | Needs | Purpose |
|---|---|---|---|
| `GET /public/plans` | none | - | Pricing/comparison page. Active + public plans, ordered by `sort_order`. Every plan lists ALL features and limits. |
| `GET /subscription` | session | `subscription.view` | The farm's subscription (`plan`, `status`, period dates, `cancel_at_period_end`, `effective_plan`, `subscription_inactive`). |
| `GET /subscription/entitlements` | session | `farm.view` | What the plan allows now: `features` (bool map) and `limits` (`{limit, unlimited}` map). Any member can read it to enable/disable UI. |
| `GET /subscription/usage` | session | `subscription.view` | Per limit: `limit`, `usage`, `remaining`, `exceeded`, `unlimited`. |
| `POST /subscription/cancel` | session | `subscription.manage` | Schedule the paid subscription to end at period end (`409 subscription_not_cancellable | subscription_already_cancelled`). |
| `POST /subscription/resume` | session | `subscription.manage` | Withdraw a scheduled cancellation (`409 subscription_not_cancelled`). |

**Not available yet:** checkout/payment and billing webhooks (no payment provider chosen). Do not build a payment UI;
plan changes are not user-initiated in this phase.

## 11. Data conventions

- Money is integer **minor units** (`amount_minor`, kobo for NGN: `300000` = ₦3,000.00). `formatted` is display-only.
- Unlimited is explicit: `{"limit": null, "unlimited": true}`; `remaining` is `null` when unlimited. There is no "big number".
- Every farm always has a subscription (new farms start on the default plan). `effective_plan` is the plan actually
  honoured; if the paid period lapsed or the plan was retired, `subscription_inactive` is `true` and the default plan applies.
- A downgrade never deletes data. If usage is above the new limit, `exceeded` is `true`, `remaining` is `0`, existing
  data stays readable, and only NEW capacity-consuming actions are refused.
- Plan `key`s (`features[].key`, `limits[].key`) are stable machine identifiers: `advanced_reports`, `data_export`, `team_members`.
  New keys appear in the lists without an API change.
- Team-member usage = active members (Owner included) + pending invitations (expired/revoked/removed do not count).

## 12. Entitlement errors

Error envelope as usual, plus optional `details`:

| HTTP | `code` | Meaning | `details` |
|---|---|---|---|
| 403 | `feature_not_available` | The plan does not include the feature | `entitlement_key` |
| 403 | `subscription_inactive` | The subscription lapsed; renew to use this | `entitlement_key` |
| 409 | `plan_limit_reached` | The plan's capacity is used up (e.g. inviting when the team limit is reached) | `entitlement_key`, `limit`, `usage`, `remaining` |

Currently enforced: `POST /farm/invitations` (and resending an EXPIRED invitation) -> `plan_limit_reached` for `team_members`.
Show an upgrade prompt on these codes; show a permissions message on `403 forbidden`.

---

# Phase 4 - Agricultural master data

## 13. What master data is (and is not)

Master data describes what the system UNDERSTANDS (operations, species, crops, breeds, varieties, capabilities). It never
records what happened on the farm. Drive forms from these APIs; never branch on `name` (`"Poultry"`), use stable `code`,
`category`, `tracking_model` and capability codes. All routes are farm-scoped (session farm, optional `X-Farm-Id`), need
`master_data.view` (every role) and are NOT plan-gated.

## 14. Populating selectors

| Step | Endpoint | Notes |
|---|---|---|
| Production type | `GET /master/farm-operations` | `category` = `livestock` / `aquaculture` / `crop`; `tracking_model` = `population` (livestock, fish: initial head count) or `planting_units` (crops). Filters: `category`, `available=true`, `include_inactive=true`. |
| Species | `GET /master/species?operation=poultry` | Filters: `operation` (operation code), `category`, `group` (`poultry`, `small_ruminants`, `large_ruminants`, `non_ruminant_mammals`, `equines`, `micro_livestock`), `available=true`. Each item has `livestock_group` (`{code,name}`, null for fish) and `capability_codes` (enabled). 21 livestock species + fish; see [LIVESTOCK-CATALOGUE.md](LIVESTOCK-CATALOGUE.md). |
| Species behaviour | `GET /master/species/{id}/capabilities` | All 11 capabilities with `enabled` and `reference` (biological REFERENCE data: a single value `{incubation_days: 21}`, or a range `{gestation_days_min: 59, gestation_days_max: 72}` (never a midpoint), optional `approximate` / `note`; `null` when none - e.g. snail has only a `note`). Reference values are starting points - not guarantees, not farm targets, not recorded outcomes. Show/hide fields from `enabled`. |
| Breed dropdown | `GET /master/species/{id}/breeds` | System breeds + THIS farm's custom breeds, active only. `source` = `system` \| `farm`. `include_inactive=true` to display historical values. |
| Crop | `GET /master/crops` | Crops belong to the `crops` operation (`tracking_model: planting_units`). |
| Variety dropdown | `GET /master/crops/{id}/varieties` | Same system + farm rule. A variety is optional; the list may be empty. |
| Planting lists | `GET /master/planting-reference` | `material_types` (what is planted) and `unit_types` (how it is counted) are SEPARATE. Number of planting units is not seed/material quantity. |

Selectors show only active items. An inactive item is not selectable for new records but stays resolvable (use `include_inactive=true` to label old data).
The catalogue ships only reviewed defaults: there are currently **no system breeds or varieties**, so the breed/variety lists
start empty and fill with the farm's custom items.

## 15. Custom breeds and varieties ("+ Add custom breed")

| Endpoint | Needs | Purpose |
|---|---|---|
| `GET /custom-breeds`, `GET /custom-varieties` | `master_data.view` | This farm's custom items (filters `species_id` / `crop_type_id`, `include_inactive`). |
| `POST /custom-breeds` `{species_id, name}` | `master_data.manage` (Owner, Manager) | Create. `201`. |
| `POST /custom-varieties` `{crop_type_id, name}` | `master_data.manage` | Create. `201`. |
| `PATCH /custom-breeds/{id}`, `PATCH /custom-varieties/{id}` `{name?, is_active?}` | `master_data.manage` | Rename, deactivate (`is_active:false`), reactivate. |

- No delete: deactivate instead. Farm, parent (species/crop) and `code` can never change; sending `farm_id`, `code` or the parent id on update -> `422`.
- The farm always comes from your session. System items and other farms' items are `404` on PATCH (never `403`, never editable).
- Names are trimmed (2-100 chars). Duplicates (case/space-insensitive, versus system items and this farm's items of the same parent) -> `409 duplicate_name` with `details: {existing_id, source, is_active}`; reuse `existing_id`, or reactivate it when `is_active` is false.
- Other errors: `403 forbidden`, `422` (unknown/inactive species or crop, invalid name), `429`.
- The frontend hides the "+ Add custom breed" option when `membership.permissions` (from `GET /farm`) lacks `master_data.manage`.

## 16. Optional farm operations

`GET /farm/operations` (`farm.view`) returns `{configured, operations[]}`. `PUT /farm/operations` `{operation_ids: [...]}` (`farm.update`) replaces
the selection (empty array clears it). This is never part of onboarding and nothing depends on it: a farm with NO selection is
"unconfigured" and every operation is `available`. Once operations are selected, `available=true` filters narrow the species/crop
lists to them; the unfiltered lists still return everything. Errors: `422` for unknown/inactive/duplicate ids.

## 17. Response shape notes

Items carry `id` (UUID), `code` (stable; null for custom items), `name`, `source`, `is_active`, `is_editable` (custom items only).
Operation items also carry `selected` (explicit choice) and `available` (usable now). Not yet available: record-type/form field
schemas (`GET /master/record-types`) and task categories arrive with later phases; units are documented in section 18.

## 18. Measurements: dimensions, units and conversions (Phase 5)

**Rule: never load "all units".** Every quantity field belongs to a *measurement dimension*; ask the API for that dimension's units only.
The frontend keeps no unit-compatibility table.

| Endpoint | Needs | Purpose |
|---|---|---|
| `GET /master/measurement-dimensions` | `measurement.view` (all roles) | `weight`, `volume`, `area`, `count`, `temperature`, `package` + `canonical_unit`, `supports_preference`. |
| `GET /master/units?dimension={code}` (`&family=`, `&include_inactive=true`) | `measurement.view` | Units of ONE dimension (`dimension` is required; unknown -> `422`). |
| `GET /settings/units` | `measurement.view` | Farm default unit per weight/volume/area/temperature (`is_default` true = nothing chosen yet: kg, L, hectare, Celsius). |
| `PUT /settings/units` `{preferences: {weight: "kg", volume: null}}` | `measurement.manage` (Owner, Manager) | Set a default unit (`null` resets). Partial: omitted dimensions unchanged. Display/entry default only - never changes stored quantities. |
| `GET /settings/measurement-contexts` (`include_inactive`) | `measurement.view` | This farm's own contexts ("Feed Grower Mash", "Eggs"), each with a stable `id`. |
| `POST /settings/measurement-contexts` `{name}` | `measurement.manage` | Create a context. `201`; duplicate name (ignoring case/spaces) -> `409 measurement_context_exists`. |
| `PATCH /settings/measurement-contexts/{id}` `{name?, is_active?}` | `measurement.manage` | Rename / deactivate / reactivate. Renaming never breaks anything that uses the `id`. |
| `GET /settings/package-conversions` (`context_type`, `context_id`, `package_unit`, `include_inactive`) | `measurement.view` | This farm's package definitions. |
| `POST /settings/package-conversions` | `measurement.manage` | Define what a package holds in a context (`context_type` + `context_id`). `201`. |
| `PATCH /settings/package-conversions/{id}` | `measurement.manage` | Change quantity / target unit / `is_active`. |
| `POST /measurements/normalize` | `measurement.view` | Live preview of an entered (compound) quantity. |

Measurement is **not plan-gated**. There are no endpoints to create or edit units: standard units (kg, L, hectare...) are system-owned.

### Which units does a field get?

A form field declares its dimension; the dropdown is `GET /master/units?dimension=...`:

| Field | Request | Options returned |
|---|---|---|
| Water consumed | `dimension=volume` | `ml`, `cl`, `l` (never kg, acre, crate, head) |
| Animal live weight | `dimension=weight` | `mg`, `g`, `kg`, `tonne`, `lb` |
| Land area | `dimension=area` | `sq_m` (m²), `hectare`, `acre` |
| Temperature | `dimension=temperature` | `celsius`, `fahrenheit` |
| Eggs / pieces | `dimension=count&family=piece` (or `egg`) | `piece` |
| Animal population | `dimension=count&family=head` | `head` (whole numbers only) |
| Medicine dose (weight OR volume) | call the endpoint for each allowed dimension | merge the two lists |
| "Package" picker (bag, crate...) | `dimension=package` | `bag`, `sack`, `crate`, `tray`, `carton`, `bottle` |

Unit fields: `code` (use this, never the label), `name`, `symbol`, `dimension`, `family`, `is_canonical`, `integer_only` (no fractions: heads, eggs),
`decimal_places` (display hint), `is_active` (selectable for NEW entries), `source` (`system`). Units are converted only inside one `family`; count units
are each their own family, so 50 heads never becomes 50 eggs. Use `unit` codes in requests, e.g. `"unit": "kg"`.

### Package conversions: a bag has no universal size

`bag`, `crate` etc. are just containers. What they hold is a farm setting *per context*. A context is **always identified by `(context_type, context_id)`** -
an id, never typed text. `label` is display only and may change:

| `context_type` | `context_id` is | Where the frontend gets it |
|---|---|---|
| `crop_type` | an active crop's id | `GET /master/crops` (e.g. Maize) |
| `custom` | one of THIS farm's active measurement contexts | `GET /settings/measurement-contexts`, or create one with `POST` ("+ Add") |

`inventory_item` (an item's UUID, Phase 9) is now a third `context_type`: see [Phase 9 inventory](PHASE-09-INVENTORY.md). A custom context does **not**
automatically become an inventory item later.

```json
POST /settings/measurement-contexts        { "name": "Feed Grower Mash" }   ->  { "data": { "id": "0198...", "name": "Feed Grower Mash", "is_active": true, ... } }

POST /settings/package-conversions
{ "context_type": "custom", "context_id": "0198...", "package_unit": "bag", "target_unit": "kg", "quantity_per_package": "25" }

{ "context_type": "crop_type", "context_id": "<maize id from GET /master/crops>", "package_unit": "bag", "target_unit": "kg", "quantity_per_package": "50" }
```

An id that is unknown, malformed, of the wrong type, inactive, or belongs to another farm is rejected with `422` on `context_id` (`context.id` when normalizing);
`context_label`/`crop_type_id` are no longer accepted. Feed's bag (25 kg) and Maize's bag (50 kg) coexist. The response `context` is `{type, id, label}`; reuse `type`/`id` as the `context` of later calls.
Renaming a context (`PATCH /settings/measurement-contexts/{id}`) changes `label` everywhere and nothing else; stored snapshots keep the name they were taken under.
One definition per (context, package): a second POST -> `409 conversion_exists` (`details.existing_id`); PATCH it. Package and context never change.
Deactivate with `{"is_active": false}` (no delete). `version` increases when the meaning changes. Quantities are exact decimal **strings**
(up to 12 digits before and 6 after the point); numbers are accepted but send strings. A count target (`piece`, `egg`) needs a whole number.

### Compound quantities and normalization

`POST /measurements/normalize` (the same service later modules use):

```json
{ "components": [ {"quantity": "3", "unit": "crate"}, {"quantity": "14", "unit": "piece"} ],
  "context": {"type": "custom", "id": "<measurement context id of Eggs>"} }
```
```json
{ "data": {
  "entered":    [ {"quantity": "3", "unit": "crate"}, {"quantity": "14", "unit": "piece"} ],
  "normalized": {"quantity": "104", "unit": "piece"},
  "total":      {"quantity": "104", "unit": "piece"},
  "snapshot":   { "schema": 1, "entered": [...], "packages": [{"conversion_id": "...", "version": 1, "per_package": "30", ...}], ... } } }
```

- `entered` is what the user typed - show/edit this. `normalized` is the total in the dimension's canonical unit (g, ml, m², °C, or the count unit itself).
  `total` is the same amount in `result_unit` (optional; default: the package target unit, else the first part's unit).
- `context` is required whenever a package unit is used. The API never guesses: no context -> `422 conversion_context_required` (or
  `ambiguous_conversion` when several contexts define that package; `details.candidates` lists them).
- Later operational records store `entered`, `normalized` and the `snapshot`; the snapshot keeps the exact conversion used, so editing or
  deactivating a package conversion afterwards never changes past records.
- Temperature converts with an offset (100 °C -> 212 °F); temperatures cannot be added together. Results round half away from zero to 6 decimals.

### Measurement errors

All `422` unless noted; body `{message, code, request_id, details?}`.

| `code` | Meaning / `details` |
|---|---|
| `incompatible_units` | Different dimensions/families (kg -> L, head -> egg, bag -> kg without context). `from_unit`, `to_unit`, `from_dimension`, `to_dimension`. |
| `conversion_context_required` / `ambiguous_conversion` | Package unit used without `context`. `unit`, `candidates[]`. |
| `conversion_not_configured` | The farm has no (active) definition for that package in that (valid) context. `unit`, `context`, `inactive`. |
| `invalid_quantity` | Malformed, over-precise, too large, negative (non-temperature), fraction for `integer_only`. `unit`, `reason` (`fraction_not_allowed`). |
| `invalid_conversion_ratio` | Package quantity zero, negative, malformed, or fractional for a count target. |
| `unknown_unit` / `unit_not_selectable` | Unit code not found / inactive for new entries. `unit`. |
| `unit_dimension_mismatch` | Unit of the wrong dimension (kg as a volume preference). `unit`, `required_dimension`. |
| `conversion_exists` (`409`) | Definition already exists. `existing_id`, `is_active`. |
| `measurement_context_exists` (`409`) | A context with that name already exists in this farm. `existing_id`, `is_active`. |
| `403 forbidden`, `404 not_found`, `429` | Missing `measurement.manage`; another farm's or unknown conversion/context id; rate limit. |

## 19. Farm places (Phase 6)

Places answer **“Where on my farm is this?”** and belong under Farm / Places or contextual creation in operational forms. Onboarding still asks for **Farm Name only**. A farm starts with zero places and has full application access. No locations are created automatically, and no subscription entitlement gates these endpoints.

### Entities and hierarchy

The ERD's three entities are retained as separate tables and API collections:

| Collection | Purpose | Allowed `type` codes |
|---|---|---|
| `locations` | Optional sites / containing buildings or fields; may contain other locations | `site`, `building`, `house`, `field`, `other` |
| `production-areas` | Places future production cycles will reference | `house`, `pen`, `pond`, `field`, `plot`, `other` |
| `storage-locations` | Stores future inventory records will reference | `store`, `other` |

`other` supports custom places (e.g. a tank, greenhouse or nursery) without inventing a larger reviewed type list. Names remain farmer-defined. A house used as a container is a location; a house hosting production directly can be a production area. Types convey physical purpose only, never biological behaviour.

Every entity belongs directly to a farm. Every `parent_id` is optional and refers **only to a location UUID in that farm**, including on areas and stores. Internally, areas/stores store that relationship in `location_id`. They are terminal entities and cannot parent anything. Locations can nest up to seven ancestors (root depth 0, deepest depth 7), counting the final area/store too. There is no fake “Farm” root record and no required intermediate site. Future production cycles reference `production_areas.id`; inventory references `storage_locations.id`.

Flat farm: create three production areas with no parent:

```json
{"name":"Broiler Pen","type":"pen"}
{"name":"Pond A","type":"pond"}
{"name":"Cassava Plot","type":"plot"}
```

Hierarchical farm: `POST /locations` with `{"name":"Poultry House","type":"house"}`, then `POST /production-areas` with `{"name":"Pen 1","type":"pen","parent_id":"<house UUID>"}` and the same for Pen 2. An optional Abuja Site can be another location above the house. `POST /storage-locations` with `{"name":"Main Store","type":"store"}` works directly under the farm.

### Authentication and endpoints

All paths below have prefix `/api/v1`. Use the existing Sanctum SPA cookie session and CSRF header on writes. User must be active, verified, onboarded and an active farm member (`app.access`, `farm.context`). Optional `X-Farm-Id` selects an active membership, never grants access. Do not send `farm_id` in body or query. The server resolves ownership.

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/master/location-types` | `location.view` | 200, shared read-only catalogue |
| GET | `/locations` | `location.view` | 200, paginated locations |
| POST | `/locations` | `location.manage` | 201, created location |
| GET | `/locations/{place}` | `location.view` | 200, including inactive |
| PATCH | `/locations/{place}` | `location.manage` | 200, updated location |
| GET | `/production-areas` | `location.view` | 200, paginated areas |
| POST | `/production-areas` | `location.manage` | 201, created area |
| GET | `/production-areas/{place}` | `location.view` | 200, including inactive |
| PATCH | `/production-areas/{place}` | `location.manage` | 200, updated area |
| GET | `/storage-locations` | `location.view` | 200, paginated stores |
| POST | `/storage-locations` | `location.manage` | 201, created store |
| GET | `/storage-locations/{place}` | `location.view` | 200, including inactive |
| PATCH | `/storage-locations/{place}` | `location.manage` | 200, updated store |

All four roles can view; Owner and Manager can manage. POST/PATCH share a 60/hour/user limiter across all three collections. No DELETE endpoint. `{place}` is the UUID of that collection's entity, never its name.

The catalogue is returned in `{data:[{kind,types:[{code,label}]}],meta:{},message:null}`. Kinds are `location`, `production_area`, `storage_location`; codes/labels match the table above, with title-case labels. It cannot be edited by farms and is not copied per farm.

### Create and update body

| Field | POST | PATCH | Validation / meaning |
|---|---|---|---|
| `name` | Required | Optional | String, 1–100 characters after trim and whitespace collapse; arbitrary farmer-friendly names |
| `type` | Required | Optional | Stable code from that collection's catalogue; null invalid |
| `parent_id` | Optional, defaults null | Optional; explicit null detaches | UUID of a same-farm location; active ancestors required for creates, moves and reactivation |
| `is_active` | Optional, defaults true | Optional | Boolean; false archives, true reactivates |

PATCH retains omitted values; an empty/no-change PATCH is a successful no-op. `id`, `farm_id`, `location_id`, `normalized_name`, `parent_scope` are server-owned and rejected. Operation assignment, area and capacity are unsupported in Phase 6. No measurements, stock or population are stored here.

**Duplicate invariant:** within the **same resource kind + farm + parent**, names must be unique after whitespace collapse/trim and Unicode lowercase. Inactive records reserve their names. Case-only display renames are allowed. Names in different branches, farms or resource kinds may repeat; different kinds have distinct selectors and UUID namespaces. For example, Site A / Store and Site B / Store are valid. No accent folding or slug identity is used. MySQL binary-collated normalized names and a generated non-null parent key protect duplicate siblings, including top-level places. All writes share a farm-row lock for concurrent moves and deactivation checks.

Cycles/self-parenting are rejected. Reparenting checks the depth of the **whole subtree**, including inactive descendants and terminal areas/stores. Composite foreign keys enforce parent ownership at the database level too.

### Resource and contextual creation

Example `POST /production-areas` body:

```json
{"name":"Pen 1","type":"pen","parent_id":"019f0000-0000-7000-8000-000000000001"}
```

201 response (the referenced house must already exist):

```json
{
  "data": {
    "id": "019f0000-0000-7000-8000-000000000002",
    "kind": "production_area",
    "name": "Pen 1",
    "type": "pen",
    "type_label": "Pen",
    "parent_id": "019f0000-0000-7000-8000-000000000001",
    "path": [
      {"id":"019f0000-0000-7000-8000-000000000001","kind":"location","name":"Poultry House"},
      {"id":"019f0000-0000-7000-8000-000000000002","kind":"production_area","name":"Pen 1"}
    ],
    "path_label": "Poultry House / Pen 1",
    "depth": 1,
    "is_active": true,
    "created_at": "2026-09-29T10:00:00+00:00",
    "updated_at": "2026-09-29T10:00:00+00:00"
  },
  "meta": {},
  "message": "Place created."
}
```

GET item uses the same resource with `message:null`; PATCH uses `message:"Place updated."`. UUIDv7 identities remain unchanged by rename, move, type change or deactivation. Paths include the resource itself and current ancestor names; historical references keep their UUID and initially display current names. `path_label` is display text, never parsed for identity (names may contain `/`). The path array provides structure even when a list is filtered or paginated. Ancestors are eager-loaded in bounded queries, avoiding per-row lookups.

After contextual creation, select the returned UUID immediately and refresh the relevant collection. React needs no extra requests to construct the path. There is no separate tree endpoint: management can build trees from the location collection using `parent_id` and attach areas/stores by the same field. Follow all pages when building a complete tree.

### List filters

All three collection GETs accept these query parameters:

| Parameter | Default | Behaviour |
|---|---|---|
| `include_inactive` | `0` | `1` includes inactive; otherwise only active |
| `type` | Omitted | Exact code, must be valid for this collection |
| `parent_id` | Omitted | Direct children of this same-farm location UUID; unknown/foreign returns 404 |
| `top_level` | `0` | `1` returns only rows without a parent; incompatible with `parent_id` |
| `search` | Omitted | Literal case-insensitive normalized-name substring, 1–100 characters; `%` and `_` are literal |
| `page` | `1` | Integer >=1 |
| `per_page` | `50` | Integer 1–100 |

Use `0`/`1` for query booleans. No operation filter exists: the docs do not define a compatibility model, and repurposing a place remains possible. `operation` is rejected rather than silently returning irrelevant choices.

Example: `GET /api/v1/production-areas?type=pen&parent_id=<house UUID>&per_page=50`. Response is `{data:[<resources>],meta:{current_page:1,per_page:50,last_page:1,total:2},message:null}`. Empty results have `data:[]` and `total:0`. Ordering is normalized name, then UUID; filters never change farm scope.

### Deactivation and future assignments

`PATCH /locations/{place}` with `{"is_active":false}` deactivates a location. First deactivate or move **all active descendants**, including production areas and stores; nothing cascades automatically. Reactivate ancestors before children. An inactive child can still be renamed under an inactive parent, or moved to an active parent/root. Creates and moves cannot target an inactive parent even if the new child is inactive. GET item and `include_inactive=1` remain available for history. Reactivation does not reactivate descendants.

New operational assignments must use the shared server-side `PlaceService::selectable(farm, kind, UUID)` gate in later phases; it rejects inactive rows/ancestors and scopes every lookup to the farm. Phase 6 creates no operational records. Current names resolve through stable UUIDs; no premature name snapshots are introduced.

### Errors and audit hook

| HTTP | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | Missing session |
| 403 | `forbidden` | Missing view/manage permission |
| 403 | `account_suspended`, `email_verification_required`, `onboarding_required`, `no_active_farm`, `farm_access_denied` | Existing access/FarmContext gates |
| 404 | `not_found` | Unknown, wrong-kind or foreign-farm resource/parent UUID; existence of another farm's data is not disclosed |
| 409 | `duplicate_location` | Duplicate normalized sibling name in this resource kind, including inactive rows |
| 409 | `location_cycle` | Self-parent or descendant used as parent |
| 409 | `location_depth_exceeded` | Proposed hierarchy/subtree exceeds seven ancestors |
| 409 | `location_inactive` | Inactive parent/ancestor for a create, move, activation or future operational assignment |
| 409 | `location_has_active_children` | Deactivation would strand active descendants |
| 419 | `session_expired` | CSRF/session failure |
| 422 | `validation_failed` | Invalid fields/type/UUID/filters; field errors included |
| 429 | `too_many_requests` | Write limit exceeded; retry after `Retry-After` |

Validation example:

```json
{"message":"The selected type is invalid.","code":"validation_failed","request_id":"<request UUID>","errors":{"type":["The selected type is invalid."]}}
```

Conflict example:

```json
{"message":"A location cannot be its own ancestor.","code":"location_cycle","request_id":"<request UUID>"}
```

`PlaceChanged` emits `created`, `updated`, `deactivated`, or `reactivated` after commit with entity, farm (on entity), actor and old/new values for name/type/parent/activity. A combined edit emits one event with all changes; parent changes and renames use `updated` unless activation also changes. No-op or failed writes emit nothing. Durable platform audit storage remains a later phase.

**Explicit deferrals:** operation compatibility (neither a single operation nor a many-to-many relationship is defined by Phase 6 docs), physical area, capacity, GPS/address, additional type codes, production cycles, inventory and operational records. Later quantities must use Phase 5 `QuantityNormalizer` and preserve entered values, normalized values and conversion snapshots. Land area, planting units, capacity and actual population remain separate concepts.

## 20. Production cycles — livestock batches and crop projects (Phase 7)

Use **Livestock Batch** (including fish) and **Crop Project** in the UI. The canonical backend routes are `/api/v1/production-cycles`; no duplicate domain aliases. All calls use the existing verified, onboarded Sanctum session, CSRF on writes, and current-farm context (`X-Farm-Id` when choosing another membership). Never send `farm_id`.

| Method | URL after `/api/v1` | Permission | Success |
|---|---|---|---|
| GET | `/production-cycles` | `production_cycle.view` | 200 paginated resources |
| POST | `/production-cycles` | `production_cycle.create` | 201 full resource |
| GET | `/production-cycles/{cycle}` | `production_cycle.view` | 200 full resource |
| PATCH | `/production-cycles/{cycle}` | `production_cycle.update` | 200 full resource |
| POST | `/production-cycles/{cycle}/close` | `production_cycle.close` | 200 full resource |
| POST | `/production-cycles/{cycle}/reopen` | `production_cycle.reopen` | 200 full resource |
| GET | `/production-cycles/{cycle}/summary` | `production_cycle.view` | 200 full resource (Phase 7 baseline summary) |
| GET | `/production-cycles/{cycle}/activity` | `production_cycle.view` | 200 paginated lifecycle events |

All four roles can read. Owner and Manager can create, edit, close and reopen. Finance and Farm Worker cannot manage cycles. Assigned-cycle permissions are deferred until assignments exist. Each mutation is also authorized inside the service. Cycle writes share a 60/hour/user throttle. No DELETE endpoint.

### Create fields and dependent selectors

Common required fields: `kind` (`livestock` or `crop`), `name` (1–100 characters after trimming/collapsing whitespace), `operation_type_id` (UUID). Common optional fields: `production_area_id` (UUID or null), `notes` (string up to 5000 or null), `expected_end_date` (`YYYY-MM-DD` or null). Server generates UUIDv7 `id`, human `reference`, and `status=active`. Reference example `BAT-2026-00001` or `CRP-2026-00002`; numbering is farm-wide, includes both kinds, and does not reset annually. UUID is the resource identity. Names are case/whitespace-normalized and unique per farm and kind, including closed cycles. The same name can be used by another farm or by the other kind.

| Domain | Required fields | Optional fields |
|---|---|---|
| livestock | `species_id`, `initial_population`, `start_date` | `breed_id` |
| crop | `crop_type_id`, `planting_material_type`, `planting_unit_type`, `initial_planting_units`, `planting_date` | `crop_variety_id`, `area`, `expected_germination_date` |

Counts must be positive whole numbers, 1–999999999999. Booleans, fractional counts, unit strings, zero and negatives are rejected. Counts may be JSON integers or canonical digit strings without leading zeroes. Livestock counts always mean heads, including fish; no kg/litre/package population. Optional master UUIDs accept null. Dates must be actual `YYYY-MM-DD` calendar dates; historical dates are valid and independent of entry time. Future start/planting dates are accepted without inventing a draft status. Expected dates are nullable, user-entered estimates; they cannot precede start/planting. No biological duration is assumed. `expected_germination_date` is crop-only.

Resolve dependencies using the existing Phase 4 endpoints:

1. `GET /master/farm-operations` returns operations and `tracking_model`; `population` uses the livestock shape (fishery included), `planting_units` uses the crop shape.
2. `GET /master/species?operation=poultry` returns relevant species; `GET /master/species/{species}/breeds` returns system/current-farm breeds. Create a custom breed through `POST /custom-breeds`, then use its UUID.
3. `GET /master/crops?operation=crops`, then `GET /master/crops/{crop}/varieties`; custom varieties use `POST /custom-varieties`.
4. `GET /master/planting-reference` returns active codes for `planting_material_type` (seed, seedling, stem_cutting, tuber, sucker, other) and `planting_unit_type` (heap, hole, stand). Render catalogue responses rather than copying these lists into the client. Added active catalogue entries are supported.
5. `GET /production-areas` provides current-farm active areas. **Zero production areas is valid.** Omit the field or send null. Areas can host multiple active cycles and have no invented operation/type compatibility restrictions.

Operation/species and operation/crop relationships are validated. Breed must belong to the species; variety to the crop. New assignments require active catalogue entries and system/current-farm visibility. Unknown/foreign UUIDs return 404. Historical selections remain readable after deactivation and descriptive edits do not require reselecting them. New area assignments require active ancestors too. Farm-operation selections remain optional selector preferences, as in Phase 4, and do not block an otherwise valid cycle.

### Examples

Replace angle-bracket placeholders with UUIDs from the selectors.

Poultry → Chicken → optional breed → 500 heads → optional Broiler Pen:

```json
{
  "kind": "livestock",
  "name": "October Broilers",
  "operation_type_id": "<poultry-uuid>",
  "species_id": "<chicken-uuid>",
  "breed_id": null,
  "initial_population": 500,
  "start_date": "2026-10-01",
  "production_area_id": null,
  "expected_end_date": null,
  "notes": "First flock"
}
```

Fishery → Fish → 2,000 heads → optional Pond A (same livestock model):

```json
{
  "kind": "livestock",
  "name": "Catfish Batch A",
  "operation_type_id": "<fishery-uuid>",
  "species_id": "<fish-uuid>",
  "initial_population": 2000,
  "start_date": "2026-09-01",
  "production_area_id": "<pond-a-production-area-uuid>"
}
```

Yam → optional variety → tuber → heap → 800 heaps → optional Yam Plot:

```json
{
  "kind": "crop",
  "name": "2026 Yam",
  "operation_type_id": "<crops-uuid>",
  "crop_type_id": "<yam-uuid>",
  "crop_variety_id": null,
  "planting_material_type": "tuber",
  "planting_unit_type": "heap",
  "initial_planting_units": 800,
  "planting_date": "2026-04-10",
  "production_area_id": null,
  "area": {"quantity": "2", "unit": "hectare"}
}
```

**800 heaps != 800 tubers.** Planting units are the baseline for later establishment/survival checks. The API never derives seed/tuber/seedling quantity, inventory consumption, or stock deductions. `material_quantity` is rejected. Actual material use belongs to later input/operational workflows.

Land area is optional and separate: `{quantity,unit}`, with a positive quantity and an active AREA unit from `GET /master/units?dimension=area` (`hectare`, `sq_m`, `acre`). The Phase 5 engine preserves entered representation, normalized quantity/unit and conversion snapshot. `2 hectare` normalizes to `20000 sq_m`; it does not affect the 800 heaps. Weight, volume and count units are rejected. Package units are not usable without a conversion context (this field deliberately has none).

### Resource and success envelopes

All create/show/update/close/reopen/summary responses use the same resource. `meta` is `{}` for a single record. Create message is `Production cycle created.`; update/close/reopen messages identify that action; GET messages are null.

```json
{
  "data": {
    "id": "<cycle-uuid>",
    "kind": "livestock",
    "name": "October Broilers",
    "reference": "BAT-2026-00001",
    "status": "active",
    "operation": {"id": "<poultry-uuid>", "code": "poultry", "name": "Poultry", "tracking_model": "population"},
    "production_area": null,
    "start_date": "2026-10-01",
    "planting_date": null,
    "expected_end_date": null,
    "end_date": null,
    "notes": "First flock",
    "baseline_locked": true,
    "livestock": {
      "species": {"id": "<chicken-uuid>", "code": "chicken", "name": "Chicken"},
      "breed": null,
      "initial_population": 500,
      "current_population": 500,
      "population_unit": "head",
      "population_basis": "population_movements"
    },
    "crop": null,
    "created_at": "2026-09-30T08:00:00.000000Z",
    "updated_at": "2026-09-30T08:00:00.000000Z"
  },
  "meta": {},
  "message": "Production cycle created."
}
```

A crop resource has `livestock=null`, `start_date=null`, a `planting_date`, and:

```json
{
  "crop_type": {"id": "<yam-uuid>", "code": "yam", "name": "Yam"},
  "variety": null,
  "planting_material_type": "tuber",
  "planting_material_label": "Tuber",
  "planting_unit_type": "heap",
  "planting_unit_label": "Heap",
  "initial_planting_units": 800,
  "expected_germination_date": null,
  "area": {
    "entered": [{"quantity": "2", "unit": "hectare"}],
    "normalized": {"quantity": "20000", "unit": "sq_m"}
  }
}
```

Breed/variety summaries contain `id,name,is_active`. Assigned production area is the complete Phase 6 place resource with path. Read resources resolve current names, preserving UUID identity; they do not copy names as identities. Measurement snapshot stays stored internally; entered and normalized values are returned as decimal strings.

### Editing, lifecycle and history

PATCH allows `name`, `notes`, `production_area_id`, `expected_end_date`; crops additionally allow `area`, `expected_germination_date`. Omission preserves values; null clears optional fields. Example: `{"name":"Broilers A","production_area_id":null,"notes":"Moved out"}`. An unchanged area may remain historically inactive; a new area must be selectable. No-op PATCH produces no lifecycle event.

Starting identity and baseline are **immutable from creation**: kind, operation, species, breed, initial population, start date, crop, variety, planting material/unit types, initial planting units, planting date. Sending any of these in PATCH returns `409 baseline_locked`, even if unchanged. The source provides no approved pre-activity baseline correction workflow; Phase 7 does not invent one. Reopening does not unlock this baseline. Phase 8 implements explainable population adjustments and reversals through the operational-record endpoints; the initial baseline remains immutable.

Current livestock population is SUM(population_movements.quantity). Creation inserts exactly one `initial` movement in the same transaction; its `recorded_at` is midnight on the starting domain date in the farm timezone, stored in UTC, separate from `created_at`. No editable current-population column exists. Phase 8 adds mortality, reconciliation adjustments and reversal movements to this same ledger.

Statuses: **active → closed → active** only. Close body: `{"end_date":"2026-09-20","reason":"Season finished"}`; reopen body: `{"reason":"Resume work"}`. Reasons are required, up to 2000 characters. Close date must be on/after start and on/before today in farm timezone. Closing reconciles the baseline and linked operational movements, rejects end dates before recorded activity, protects ordinary edits/new records, and releases active-cycle capacity. A nonzero livestock population does not automatically become an exit or sale; closure does not dispose of animals. Reopening clears the current `end_date`; prior dates/reasons remain in activity. Repeated close/reopen in the same state returns `409 invalid_status_transition`, without duplicate effects.

Lifecycle events are appended transactionally for created/updated/closed/reopened, with actor UUID, changes `{field:{old,new}}`, reason, `recorded_at` and `created_at`. Area reassignments preserve both IDs. Creation snapshots capture both common fields and the relevant subtype baseline. Changes to subtype fields appear under `crop` or `livestock` as before/after objects, including stored measurement metadata. `CycleChanged` dispatches after commit only. Operational activity is available separately through /records; global audit remains deferred.

Example activity envelope:

```json
{"data":[{"id":"<event-uuid>","action":"updated","actor_id":"<user-uuid>","changes":{"production_area_id":{"old":"<old-area-uuid>","new":null}},"reason":null,"recorded_at":"2026-09-30T08:00:00.000000Z","created_at":"2026-09-30T08:00:00.000000Z"}],"meta":{"current_page":1,"per_page":50,"last_page":1,"total":1},"message":null}
```

### Listing, capacity and errors

List filters: `kind`, `status`, `operation_type_id`, `species_id`, `crop_type_id`, `production_area_id`, `search`, `page`, `per_page`. UUID filters use UUIDs; kind/status use stable codes. Default includes active and closed. Search matches literal name/reference substrings (`%` and `_` are not wildcards). Ordering: start/planting date descending, UUID ascending. Page >=1; per_page 1–100, default 50. List and activity meta: `current_page,per_page,last_page,total`. Activity accepts only page/per_page, newest UUID first. Example: `GET /production-cycles?kind=livestock&status=active&per_page=25`.

Creation and reopening consume the existing entitlement service's `active_cycles` limit, counting both kinds with status active. Source section 32 defines provisional Free=3 and Pro/Business=unlimited; these are database configuration, not plan-name checks in business logic. Farm-row locking serializes growth. Closing releases a slot. Downgrade never hides/deletes history or blocks ordinary edits. New over-limit growth returns `409 plan_limit_reached` with `{entitlement_key:"active_cycles",limit,usage,remaining}`. Existing subscription/public-plan APIs expose this additional limit alongside team capacity.

| HTTP | Code | Meaning |
|---|---|---|
| 401 / 403 / 419 | existing auth/farm/CSRF codes | Sign in, onboarding/verification/current membership, permission or CSRF failure |
| 404 | `not_found` | Unknown or foreign cycle, master UUID, or production area (including foreign area filters) |
| 422 | `validation_failed` | Missing/invalid fields, mismatched relationships, inactive master selection, invalid dates |
| 422 | Phase 5 measurement codes | `unit_dimension_mismatch`, `unknown_unit`, `invalid_quantity`, `unit_not_selectable`, etc. |
| 409 | `duplicate_cycle_name` | Same farm/kind already owns the normalized name, even if closed |
| 409 | `baseline_locked` | PATCH supplied a starting identity/baseline field |
| 409 | `cycle_closed` | Ordinary edit attempted while closed |
| 409 | `invalid_status_transition` | Close already closed or reopen already active |
| 409 | `cycle_reconciliation_failed` | Baseline/initial population ledger inconsistent; no transition applied |
| 409 | `location_inactive` | New area assignment or an ancestor is inactive |
| 409 | `plan_limit_reached` | Creation/reopen exceeds active-cycle capacity |
| 429 | `too_many_requests` | Shared cycle-write rate limit; respect Retry-After |

Validation example (POST either count field at zero):

```json
{"message":"The initial population field must be at least 1.","code":"validation_failed","request_id":"<request-uuid>","errors":{"initial_population":["The initial population field must be at least 1."]}}
```

Conflict example:

```json
{"message":"Reopen the cycle before making ordinary edits.","code":"cycle_closed","request_id":"<request-uuid>"}
```

Server-owned `id,farm_id,reference,status,current_population,end_date,is_active` and `material_quantity` are rejected in create/PATCH. Do not use inactive master flags as cycle lifecycle status. Fields from the opposite kind are rejected on creation. Phase 8 records, mortality, adjustments, reversals, feeding and attachments are documented below. Birth/hatch workflows, transfers, survival checks, harvest, sales and finance remain deferred to their respective phases.

## 21. Operational records and population ledger (Phase 8)

The complete frontend integration contract is [Phase 8 records](PHASE-08-RECORDS.md): typed events, schemas, permissions, retries, population reconciliation, reversals, measurement snapshots, private attachments, examples and errors. These endpoints are also included in openapi.json.

## 22. Inventory, lots, stock movements and feed formulas (Phase 9)

The complete frontend integration contract is [Phase 9 inventory](PHASE-09-INVENTORY.md): items, lots/expiry, the movement ledger, stock-in/out, count adjustments, transfers, reversals, package conversion contexts, feed formulas, the optional `feed_use` stock link, permissions, retries, errors and examples. These endpoints are also included in openapi.json.

## 23. Health records and medicine (Phase 10)

The complete frontend integration contract is [Phase 10 health](PHASE-10-HEALTH.md): typed health records (vaccination, medication, deworming, treatment, disease/issue, vet visit), multi-medicine lines with dose and package conversions, the linked inventory deduction, lots/expiry, withdrawal windows, the medicine read model, the `vet` role preset, mortality linking, reversal/correction, permissions, retries and errors. These endpoints are also included in openapi.json.

## 24. Breeding (Phase 11)

The complete frontend integration contract is [Phase 11 breeding](PHASE-11-BREEDING.md): breeding projects (incubation and pregnancy workflows unlocked by species capabilities), the frozen biological reference, exact expected dates versus expected windows (never a midpoint; no fabricated dates for snail or honeybee), manual expectation overrides, checks, milestones, actual outcomes with the explicit population confirmation (50 eggs set, 40 expected, 37 hatched = +37 once), reversal/correction, permissions, retries and errors. These endpoints are also included in openapi.json.

## 25. Work: tasks, schedules, templates and calendar (Phase 12)

The complete frontend integration contract is [Phase 12 work](PHASE-12-WORK.md): tasks with a derived `due_state`, recurrence, templates, assignment, record-linked completion (completing never creates a record), the calendar read model, permissions and errors. These endpoints are also included in openapi.json.

## 26. Crop operations and outputs (Phase 13)

The complete frontend integration contract is [Phase 13 crop operations](PHASE-13-CROP-INPUTS.md): land preparation, planting, establishment/survival (50 planted, 47 established = 94%), growth stage, crop loss, crop harvest (optionally into `produce` stock), fertilizer and pesticide/herbicide applications with linked stock, the crop project detail `GET /production-cycles/{cycle}/crop`, plot-scoped record listing, package/lot rules, reversal/correction and errors. Everything except the detail endpoint is a record type on `/records`; these endpoints are also included in openapi.json.

## 27. Contacts, purchasing and finance (Phase 14)

The complete frontend integration contract is [Phase 14 contacts, purchasing and finance](PHASE-14-FINANCE.md): one contact with supplier/customer roles, purchases (stock lines → one Phase 9 stock-in each, non-stock lines → no movement, one linked expense), the append-only money ledger with expense/income categories, "record as expense/income" source links with duplicate prevention, cycle allocation and profitability, cancel/reverse/correct, decimal-string money, idempotency, permissions and errors. These endpoints are also included in openapi.json.

## 28. Sales, invoices and payments (Phase 15)

The complete frontend integration contract is [Phase 15 sales, invoices and payments](PHASE-15-SALES-INVOICES.md): sales (produce lines → one Phase 9 stock-out each, livestock lines → one population-ledger exit each, other lines → no physical effect), invoices as a separate snapshotted customer document with an on-demand PDF, payments as money actually received (partial and multiple payments, balances, one income entry in the Phase 14 ledger per payment), cancel/void/reverse policy (money is never implied), decimal-string money, idempotency, permissions and errors. These endpoints are also included in openapi.json.

## 29. Dashboard and insights (Phase 16)

The complete frontend integration contract is [Phase 16 dashboard and insights](PHASE-16-DASHBOARD.md): `GET /dashboard` (one read model per farm and viewer: priority strip, operation-aware KPI cards, Today & Overdue work, 7-day calendar strip, Quick Record, active production, insights, recent activity, Quick Add), `GET /dashboard/calendar` (per-day task and milestone summary) and `GET /insights` (deterministic, explainable rules). Every block is derived live from the authoritative modules and filtered by the viewer's permissions and the farm's operations; nothing is stored. These endpoints are also included in openapi.json.

## 30. Reports, exports, notifications and audit (Phase 17)

The complete frontend integration contract is [Phase 17 reports, exports, notifications and audit](PHASE-17-REPORTS.md): the report catalogue and 16 read-only reports derived from the authoritative ledgers (each also gated by the permissions of the data it reads, with operation-aware relevance and exact decimal totals), queued private CSV/XLSX/PDF exports (`202`, lifecycle `queued → processing → completed | failed → expired`, requester-only authorised download, access re-checked on every download), the notification centre (my notifications, read/unread, read-all) with channel and per-type preferences and deduplicated background evaluation of the Phase 16 insights, task reminders and export results, and the permission-protected audit trail built in place from the existing append-only records plus a small log of privileged actions. These endpoints are also included in openapi.json.
