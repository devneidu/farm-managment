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
