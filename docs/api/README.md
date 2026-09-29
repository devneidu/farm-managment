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
| `livestock.batch.create`, `inventory.adjust` (reserved) | x | x | | |
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
| Species | `GET /master/species?operation=poultry` | Filters: `operation` (operation code), `category`, `available=true`. Each item has `capability_codes` (enabled). |
| Species behaviour | `GET /master/species/{id}/capabilities` | All 11 capabilities with `enabled` and `reference` (biological REFERENCE defaults such as `{incubation_days: 21}` / `{gestation_days: 283}`, or `null`). Reference values are starting points - not guarantees, not farm targets, not recorded outcomes. Show/hide fields from `enabled`. |
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

`inventory_item` (an item's UUID) will be added as a third type when inventory exists; it needs no change to this contract. A custom context does **not**
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
