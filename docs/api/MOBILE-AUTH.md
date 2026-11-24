# Mobile (Bearer token) authentication

Native apps do **not** use the browser cookie flow (`/auth/login`, `/auth/register`, `/auth/google`, `/auth/csrf-cookie`). They sign in on
`/api/v1/auth/mobile/*`, get a **Bearer token**, and send it on every request. No cookies, no CSRF token, no `Origin` header.

The web flow is unchanged. Both flows share the same credential rules (wrong password, suspension, Google linking, OTP).

## Token flow

| Step | Request | Notes |
|---|---|---|
| Register | `POST /auth/mobile/register` `{email, password, password_confirmation, device_name}` | `201`. Creates an unverified account and emails a 6-digit OTP. Returns a token. |
| Verify email | `POST /auth/email/verify` `{code}` with `Authorization: Bearer` | Returns the new auth state. `POST /auth/email/resend` re-sends. |
| Login | `POST /auth/mobile/login` `{email, password, device_name}` | `200`. |
| Google | `POST /auth/mobile/google` `{credential, device_name}` | `201` new account, `200` existing (`meta.created`). No OTP for Google-verified emails. |
| Who am I | `GET /auth/me` | The auth state; call it on app start. |
| Log out this device | `POST /auth/logout` | Deletes the current token. |
| Log out everywhere | `POST /auth/logout-all` | Deletes every token (including this one) and all browser sessions. |
| Devices | `GET /auth/devices`, `DELETE /auth/devices/{id}` | List / revoke your own tokens. |

`device_name` is required (max 100 characters), e.g. `"Pixel 8"` or `"Chinedu's iPhone"`. It is the token's name and what the user sees in the device list.

### Response (login, register, Google)

`data` holds the token fields **and** the same payload as `GET /auth/me`, flat:

```json
{
  "data": {
    "token": "12|pYx…",
    "token_type": "Bearer",
    "expires_at": "2027-02-22T10:15:30+00:00",
    "authenticated": true,
    "email_verified": true,
    "onboarded": true,
    "has_active_farm": true,
    "next_action": "none",
    "user": { "id": "…", "email": "…", "name": "…", "has_password": true, "providers": [], "locale": null },
    "farm": { "id": "…", "name": "Green Acres", "role": "owner", "…": "…" },
    "farms": [ { "id": "…", "name": "Green Acres", "role": "owner" } ],
    "marketplace": { "shop_count": 0 }
  },
  "meta": {},
  "message": "Logged in."
}
```

The token is shown **once**. Always read `next_action` (`verify_email`, `complete_farm_setup`, `marketplace`, `no_active_farm`, `none`) to choose the screen.

Send it as `Authorization: Bearer 12|pYx…` and `Accept: application/json`.

## Storing the token

- iOS: **Keychain** (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`). Android: **Keystore**-backed encrypted storage (EncryptedSharedPreferences / `flutter_secure_storage`).
- Never put it in plain preferences, logs, crash reports, analytics, or a URL/query string.
- Do not cache API responses that contain personal data unencrypted.
- On `401` clear the stored token and show the login screen.

## Choosing the farm: `X-Farm-Id`

A user can belong to several farms (`farms[]` in the auth state). Without a header the API uses the user's oldest active farm.
To work on another farm send `X-Farm-Id: <farm uuid>` on every farm-scoped request. A farm the user doesn't actively belong to gives
`403 farm_access_denied`; a user with no active farm gets `403 no_active_farm`. This needs no session, so it works the same with a token.

## Downloading files (invoice PDF, report exports, record attachments)

These endpoints require the `Authorization` header, so **plain links, `<a href>`, WebViews and "open in browser" will not work.**
Request the file with your HTTP client and the header, save the bytes to app storage, then open them with the OS viewer / share sheet:

- `GET /invoices/{invoice}/pdf` → `application/pdf`
- `GET /reports/exports/{export}/download` → the file (CSV/XLSX/…); poll `GET /reports/exports/{export}` until `status = completed` and use its `download_path`
- `GET /records/{record}/attachments/{attachment}` → the file
- `GET /calendar-feed/{token}.ics` is different: the secret is in the URL, no header; hand `webcal_url`/`https_url` from `POST /calendar-feed` to the OS calendar.

Responses are `Cache-Control: private, no-store`; delete temporary copies when the user is done.

## Expiry and re-login

A token expires after `MOBILE_TOKEN_TTL_DAYS` (default **90**; server setting). `expires_at` is in the login response and in `GET /auth/devices`.
There is no refresh endpoint and no sliding expiry: after expiry (or revocation) the API returns `401 unauthenticated` and the app signs in again.
Tokens are also revoked when the password is reset, when the account is suspended, and by "log out of all devices".

Changing the password from the app (`PUT /account/password`) keeps **the current token** and revokes all other tokens and all browser sessions.

## Devices

`GET /auth/devices` → `data: [{ id, device_name, created_at, last_used_at, expires_at, is_current }]`, newest first, unexpired tokens only.
`DELETE /auth/devices/{id}` revokes one of **your own** tokens (`404` for anything else); revoking the current one signs this device out.

## Google sign-in

Use the native Google sign-in SDK, request an **ID token**, and post it as `credential`. The server accepts tokens whose audience
(`aud`) is one of the configured client ids (`GOOGLE_CLIENT_IDS`: web, Android, iOS); when the token has an `azp` claim it must also be
one of them. Native SDKs usually need the **Web client id** as the `serverClientId`/`clientId` so the token's audience matches.

## Errors

Standard envelope: `{ "message": "…", "code": "…", "request_id": "…" }`; validation errors are `422` with `errors{}`.

| Status | `code` | When |
|---|---|---|
| 401 | `invalid_credentials` | Wrong email or password (same answer for unknown email) |
| 401 | `invalid_google_credential` | Google token rejected |
| 401 | `unauthenticated` | Missing, malformed, expired or revoked token; also any request after the account was deleted |
| 403 | `account_suspended` | Suspended account (login, Google, or any later request) |
| 403 | `email_verification_required` | Token is valid but the email isn't verified yet |
| 403 | `farm_access_denied` / `no_active_farm` | Bad `X-Farm-Id` / no farm |
| 409 | `account_deletion_blocked` | `POST /account/delete` while a blocker exists (`details.blockers[]`) |
| 404 | – | `DELETE /auth/devices/{id}` for a token that isn't yours |
| 422 | – | Validation (`device_name` missing or over 100 characters, duplicate email, weak password) |
| 429 | `too_many_requests` | Rate limited; honour `Retry-After` |
| 503 | `google_unavailable` | Google sign-in not configured / Google keys unreachable |

## Rate limits

Login is limited per email+IP (5/min) and per IP (default 60/min); register and Google per IP. The same budgets apply to the web and mobile endpoints.
Mobile users often share an IP, so the per-IP caps are configurable (`RATE_LOGIN_IP_PER_MINUTE`, `RATE_GOOGLE_IP_PER_MINUTE`, `RATE_REGISTER_IP_PER_HOUR`).
Behind a load balancer or Cloudflare the server must trust the proxy (`TRUSTED_PROXIES`) to see real client IPs.

## Deleting the account (required by the app stores)

The same three endpoints serve the Bearer token and the web cookie. They need no verified email and no farm. Full contract, blockers, what is
deleted and what is kept: [ACCOUNT-DELETION.md](ACCOUNT-DELETION.md).

1. `GET /account/delete/check` → `{ allowed, blockers[], has_password }`. Show the blockers (each has a plain `message`) before the user starts.
2. Password account: ask for the password. Google-only account (`has_password: false`): `POST /account/delete/request-code`, then ask for the emailed code.
3. `POST /account/delete` `{ "confirm": "DELETE", "password": "…" }` or `{ "confirm": "DELETE", "code": "123456" }` → `200`. Delete the stored token and return to the signed-out screen.

After success the token is already revoked on the server; any later request, including a retry of the delete, is `401 unauthenticated`. The old email can
register again as a brand-new account. Errors: `409 account_deletion_blocked` (`details.blockers[]`), `422` (not exactly `DELETE`, wrong password, bad code), `429`.
