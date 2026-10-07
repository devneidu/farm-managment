# Frontend Integration Guide (React)

For the developer building the web frontend. It explains **how to use** the V1 API; the field-by-field reference is the Postman collection (`docs/postman/Farm-Management-API.postman_collection.json`) and the contract is [`API-CONTRACT.md`](API-CONTRACT.md). Snippets show patterns only — this is not the app.

Contents: 1 Client setup · 2 Startup · 3 Auth & routing · 4 Registration/onboarding · 5 Active farm · 6 Permissions · 7 Master data · 8 Identifiers · 9 Errors · 10 Validation · 11 Lists · 12 Idempotency · 13 Dates · 14 Measurements · 15 Money · 16 UI states · 17 Optimistic updates · 18 Refreshing after ledger actions · 19 Files · 20 Exports · 21 Notifications · 22 Platform Admin · 23 Local development checklist · 24 Building V1 Product Workflows (metadata ladder, orientation map, GAP index → detailed screens in [`FRONTEND-WORKFLOWS.md`](FRONTEND-WORKFLOWS.md))

---

## 1. Client setup (Sanctum SPA, no tokens)

* One HTTP client, created once. Cookies carry the session; there is **no bearer token** to store.
* Backend `.env` must list your frontend: `SANCTUM_STATEFUL_DOMAINS` (host:port, e.g. `localhost:5173`), `CORS_ALLOWED_ORIGINS` (with scheme), `CORS_SUPPORTS_CREDENTIALS=true`. The API host and the frontend host must be on the same site (both `localhost` in dev; `app.example.com` + `api.example.com` with `SESSION_DOMAIN=.example.com` in production).

```ts
import axios from 'axios';

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,   // e.g. http://localhost:8000/api/v1
  withCredentials: true,                    // send/receive the session cookie
  withXSRFToken: true,                      // copy the XSRF-TOKEN cookie into X-XSRF-TOKEN
  headers: { Accept: 'application/json' },
});
```

* `fetch` equivalent: `credentials: 'include'`, read the `XSRF-TOKEN` cookie, `decodeURIComponent` it and send it as `X-XSRF-TOKEN` on POST/PUT/PATCH/DELETE.
* The browser adds `Origin`; that header is what makes the API treat the call as a stateful SPA request. An existing cookie without a stateful `Origin` is rejected (`401`), and register/login without it is `400 stateful_request_required`.

## 2. Application startup

```
1. GET /auth/csrf-cookie            (once; again after any 419)
2. GET /auth/me
     401  -> show the login screen
     200  -> read data.next_action  (section 3) and data.farms[] (the farms you belong to, section 5)
3. if next_action == none:
     choose the active farm from data.farms[] (default data.farm; remembered choice if still listed)
     GET /farm                      -> farm + membership.role + membership.permissions   (send X-Farm-Id when not the default)
     GET /subscription/entitlements -> features and limits (what the plan allows)
     GET /locales + /translations/en (optional)  -> UI text
     then the screen's own data (GET /dashboard, ...)
```

Keep `auth state`, `farm` (with `membership.permissions`) and `entitlements` in one store; refresh `farm`/`entitlements` after role changes, plan changes or an accepted invitation.

## 3. Authentication and routing — `next_action` is the router

Every auth endpoint returns the same state object. **Route on `data.next_action` only**; never derive it from HTTP status or from other fields.

| `next_action` | Screen |
|---|---|
| `verify_email` | OTP entry (`POST /auth/email/verify {code}`), "resend" (`POST /auth/email/resend`, 60 s cooldown → `429` + `Retry-After`: show a countdown) |
| `marketplace` | A verified user with **no farm** who already runs a Marketplace shop: show the seller dashboard (`GET /marketplace/my/shops`). Farm routes stay locked (`403 onboarding_required` / `no_active_farm`); offer an optional "Set up a farm" → `POST /onboarding/farm`. Never returned to a user with a farm (`data.marketplace.shop_count` is returned to everyone for showing both areas) |
| `complete_farm_setup` | Farm name → `POST /onboarding/farm {name}`. A user who only wants to sell on the Marketplace can instead go straight to `POST /marketplace/shops` (no farm needed); once they have a shop the state becomes `marketplace` |
| `no_active_farm` | "You have no farm" screen (the user was removed from their last farm). Not the setup screen: `POST /onboarding/farm` would be `409 already_onboarded`. Offer "accept an invitation". |
| `none` | the app |

```ts
const ROUTES = { verify_email: '/verify-email', marketplace: '/marketplace/seller', complete_farm_setup: '/onboarding', no_active_farm: '/no-farm', none: '/' } as const;
export const routeFor = (s: { next_action: keyof typeof ROUTES }) => ROUTES[s.next_action];
```

Also handle in a global response interceptor: `401 unauthenticated` → clear stores, go to login; `403 account_suspended` → show a suspended notice and go to login; `403 email_verification_required` / `onboarding_required` / `no_active_farm` → refetch `/auth/me` and re-route (state changed under you); `419 session_expired` → refetch `/auth/csrf-cookie` and retry the request **once**.

Login: `POST /auth/login` (`401 invalid_credentials` is identical for unknown email and wrong password — never say which). Logout: `POST /auth/logout`. Password reset: `forgot` → `verify-otp` (keep the returned `reset_token` in memory only) → `reset` → send the user to login (reset does not log in). Google: obtain the ID token with Google Identity Services and `POST /auth/google {credential}`.

## 4. Registration and onboarding

```
csrf-cookie -> POST /auth/register {email, password, password_confirmation}   201, next_action=verify_email (already logged in)
            -> POST /auth/email/verify {code}                                  next_action=complete_farm_setup
            -> POST /onboarding/farm {name}                                    201, next_action=none
            -> GET /farm, GET /dashboard
```

Onboarding asks only for the **farm name**; Nigeria / `Africa/Lagos` / `NGN` / English are applied as defaults and returned in `data.farm`. Optional farm operations (`PUT /farm/operations`) are never part of onboarding.

Invited users: they register/log in with the **invited email**, verify it, then `POST /invitations/accept {token}`; they skip farm setup (`next_action` becomes `none`, `meta.accepted_farm_id` names the farm). Keep the invitation token (from the `?token=` link) in `sessionStorage` while the user signs up, never in a URL you log.

## 5. Active farm

* Do not send a farm id in URLs or bodies. By default the API uses the oldest active membership.
* To act on another farm you belong to, add `X-Farm-Id: <farm uuid>` to **every** request for that farm (an Axios request interceptor reading the selected farm from your store). A farm you don't actively belong to is `403 farm_access_denied`.
* **Discovering your farms:** `GET /auth/me` → `data.farms` = `[{id, name, role}]`, every farm you ACTIVELY belong to (oldest membership first); `data.farm` is the default one. Plans may limit this (Free = 1 farm, paid plans can hold several), so a user may have one or many entries: render a switcher only when `farms.length > 1`. There is **no switch endpoint**: selecting a farm is purely client-side.
* **Switching** (the order matters): set the selected farm id in your store → every request now carries `X-Farm-Id: <farms[].id>` → `GET /farm` (membership, role, **permissions** of that farm) → `GET /subscription/entitlements` (that farm's plan) → clear/refetch all farm-scoped data (query cache). Permissions are **never** listed in `/auth/me`; they are authoritative from `GET /farm → membership.permissions` of the selected farm.
* A farm id that is not in `farms[]` (or whose membership was removed) is `403 farm_access_denied`. If a request starts failing with `403 farm_access_denied` / `no_active_farm`, the membership was removed: refetch `/auth/me`, drop the stale selection and re-route.
* Refetch `/auth/me` after accepting an invitation (`meta.accepted_farm_id` is the new farm) so `farms[]` includes it.

## 6. Role- and permission-driven UI

`GET /farm` → `membership.permissions` is an array of strings (`inventory.manage`, `sale.create`, …). Build a tiny helper and use it for menu items, buttons and routes:

```ts
const can = (perm: string) => farm.membership.permissions.includes(perm);
{can('inventory.manage') && <Button>Receive stock</Button>}
```

* The permission each endpoint needs is written in its Postman description ("Required backend farm permission"). **Never send a permission string** in any request.
* UI hiding is a convenience; the backend answers `403 forbidden` if a permission is missing. Handle it gracefully (toast), do not crash.
* The role (`membership.role`) is for labels; do not branch on role names when a permission exists. `GET /roles` gives the preset permission lists and which roles the caller may assign (invite/change-role dialogs).
* **Plan entitlements are separate**: `GET /subscription/entitlements` (any role) gives `features` (bool map) and `limits`. Disable/upsell on `403 feature_not_available`, `403 subscription_inactive`, `409 plan_limit_reached` (read `details.limit/usage/remaining`). Never hard-code plan names or limits.
* The dashboard, notifications and insights never return 403: they return only what the viewer may see (`sections` tells you which blocks to render).

## 7. Loading master data (do not hard-code)

Fetch at the point of use and cache per farm: operations, species (+capabilities), breeds, crops, varieties, planting reference, units **per dimension**, place types, record types and schemas, health types, inventory options, task categories, finance categories, report catalogue, roles. Branch on `code` / `category` / `tracking_model` / capability codes, never on `name`.

Typical dependent selectors:

```
GET /master/farm-operations                -> operation (category, tracking_model)
GET /master/species?operation=poultry      -> species (capability_codes)
GET /master/species/{id}/breeds            -> breeds (system + farm custom); "+ Add custom breed" -> POST /custom-breeds
GET /master/units?dimension=weight         -> units for a weight field (never all units)
GET /master/record-types + /record-types/{type}/schema -> build the "record activity" form from the schema
```

Show a form section only if the species capability is `enabled`; show "+ Add custom breed" only if `can('master_data.manage')`. The catalogue ships with no system breeds/varieties yet, so those dropdowns may be empty until farms add custom ones.

## 8. Identifiers

All ids are **UUIDv7 strings** — never treat them as numbers or parse them. Keep `id` as the key; show `reference` (`BAT-2026-00001`) to humans. Membership ids (`/farm/members/{id}`) are not user ids. Unit codes, report codes, record types and master `code`s are strings you read from the API, not ids.

## 9. API error handling

One interceptor, one error type:

```ts
type ApiError = { status: number; code: string; message: string; requestId?: string; errors?: Record<string, string[]>; details?: Record<string, unknown> };

api.interceptors.response.use(r => r, (e) => {
  const b = e.response?.data ?? {};
  throw { status: e.response?.status ?? 0, code: b.code ?? 'network_error', message: b.message ?? e.message, requestId: b.request_id, errors: b.errors, details: b.details } as ApiError;
});
```

Branch on `code` (stable), show `message` (human, may change), log `requestId`.

| Status | `code` examples | Do |
|---|---|---|
| 401 | `unauthenticated`, `invalid_credentials` | login screen / form error |
| 403 | `forbidden` (missing permission), `account_suspended`, `email_verification_required`, `onboarding_required`, `no_active_farm`, `farm_access_denied`, `feature_not_available` | permission toast / re-route / upsell |
| 404 | `not_found` | "not found" (also another farm's id) |
| 409 | `insufficient_stock`, `cycle_closed`, `idempotency_conflict`, `plan_limit_reached`, … | explain the domain rule; `details` has context |
| 410 | `invitation_used/expired/revoked` | invitation screens |
| 419 | `session_expired` | refetch csrf-cookie, retry once |
| 422 | `validation_failed`, measurement codes | map `errors` to fields |
| 429 | `too_many_requests` | wait `Retry-After` seconds |
| 5xx | `server_error` | generic retry UI with `requestId` |

Unknown 409/422 `code`s must still render `message`.

## 10. Validation errors

`422` → `{message, code: "validation_failed", errors: {"field": ["…"], "details.quantity": ["…"], "items.0.amount": ["…"]}}`. Keys use dot paths into the request body (arrays by index). Map them onto inputs (`errors['details.quantity']`) and link the error summary to the first invalid field. Some domain validation uses a specific `code` with `422` (measurement: `conversion_not_configured`, `incompatible_units`, `invalid_quantity`, `unknown_unit`, `unit_dimension_mismatch`, …) — show `message` near the quantity field. Do not duplicate server rules in the client beyond basic required/format checks.

## 11. Lists: pagination, filters, search

Server-side pagination: `?page=1&per_page=50` → `meta {current_page, per_page, last_page, total}`; max `per_page` 100 (reports 500). Use query keys exactly as documented (`search`, `status`, `from`/`to`, `recorded_from`/`recorded_to`, `production_cycle_id`, …). Send booleans as `1`/`0`. To build a tree (locations) follow all pages. Drive infinite scroll / tables from `last_page`. Small catalogues are not paginated.

## 12. Idempotency

For every mutation whose request has `idempotency_key` (records, health, breeding, stock movements, finance, purchases, sales, invoices, payments, reversals, tasks, schedules, template apply, exports):

```ts
const key = crypto.randomUUID();                     // one per USER ACTION (form submit)
await api.post('/inventory/stock-in', { ...body, idempotency_key: key });
```

* Create the key when the user opens/submits the form, **keep it while retrying** after a timeout/network error, and discard it after a definitive answer. Changing the form after a failed attempt → new key.
* A retry with the same key and payload returns the original (`201`) without a second effect — safe to retry on timeouts.
* Same key + different body → `409 idempotency_conflict` (a bug in your key handling).
* Disable the submit button while pending anyway; idempotency is the safety net, not the UX.

## 13. Dates and timezones

* Send `recorded_at` as `YYYY-MM-DDTHH:MM:SS` + `Z`/offset, **no milliseconds** (`new Date().toISOString().replace(/\.\d{3}Z$/, 'Z')`). Do not send a time in the future or before the cycle start.
* Send farm-local calendar days as `YYYY-MM-DD` (`start_date`, `due_date`, `occurred_on`, report ranges). The farm timezone is `farm.timezone` (default `Africa/Lagos`); compute "today" with it (`Intl.DateTimeFormat('en-CA', { timeZone: farm.timezone })`), not the browser's zone.
* Render API timestamps (UTC) in the farm timezone. `farm.today` / `meta.today` in dashboard/calendar tell you which day the server used.
* `recorded_at` (event time) and `created_at` (stored time) are different columns — show the event time in activity lists, `created_at` only in audit/diagnostic views.

## 14. Measurements

* A quantity field knows its **dimension**; its unit dropdown is `GET /master/units?dimension=<dim>` (cache per dimension). Send `{quantity: "12.5", unit: "kg"}` strings.
* Compound entry ("3 crates + 14 pieces"): send `components` array; for package units also send/choose the **context** (`custom` context id, crop id, or the inventory item). Call `POST /measurements/normalize` to preview the total as the user types.
* Show `measurement.entered` (what the user typed) in detail views and `measurement.total`/display unit in summaries; `normalized` is canonical (g/ml/piece) for calculations.
* Count units never mix (`head` ≠ `egg`). Discrete units reject fractions.
* Handle `conversion_not_configured` with a "Define package size" shortcut (`POST /settings/package-conversions`).

## 15. Money

* Money is a **string** with ≤ 2 decimals. Keep it as a string in state and forms (`"1500.50"`); validate with `/^\d{1,16}(\.\d{1,2})?$/`.
* Do not add/multiply money with JavaScript numbers. Show server totals (`total_amount`, `outstanding`, `summary`). If you need client arithmetic (e.g. live total of lines), use integer kobo (`BigInt(whole)*100n + BigInt(frac)`) or a decimal library, and still treat the server's total as authoritative.
* Format for display only: `new Intl.NumberFormat('en-NG', { style: 'currency', currency: farm.currency })` on a value you converted for display; never send formatted text back.
* Plan prices are integer `amount_minor` (kobo) with a `formatted` string.

## 16. Loading, error and empty states

* **Loading**: skeletons per card; the dashboard is one request (`GET /dashboard`) but each block may be absent (`sections`).
* **Empty**: a new farm legitimately has no places, cycles, items. Use the API hints: dashboard `empty_state.suggested_actions`, `quick_add`, empty lists with `meta.total: 0`. Zero production areas, no breeds, no species operations selected are all valid states.
* **Error**: per-query error boundary with `requestId`; 403 on a single block should hide the block, not the page.
* **Pending side effects**: exports (`queued`/`processing`), notifications (generated hourly) are asynchronous — show their states explicitly.

## 17. Optimistic updates — where safe, where not

| Safe (revert on error) | Not safe — wait for the server |
|---|---|
| Marking a notification read (`POST /notifications/{id}/read`, idempotent) | Anything that creates an event/ledger row: records, mortality, stock, health, breeding outcomes, purchases, sales, invoices, payments (validation, population/stock checks, `insufficient_*`, `cycle_closed`, idempotency) |
| Toggling notification preferences | Reversals/cancellations (can fail on later movements) |
| Renaming/deactivating a custom breed/variety/context/place (simple CRUD) | Anything showing derived numbers: population, stock, balances, outstanding, survival %, totals, `payment_status` |
| Task assignment/title edits (still validate on reply) | Task completion with evidence (409 evidence rules) |

After a successful write, **replace local state with the response resource**; never patch derived numbers locally.

## 18. Refreshing after ledger-affecting actions

The server derives population, stock, money and statuses; refetch what the action touched:

| Action | Refetch |
|---|---|
| Record mortality / population adjustment / reverse | the cycle (`current_population`), records list, dashboard, insights |
| Feed use linked to stock; harvest linked to produce; planting/fertilizer with stock | the inventory item(s) and movements, the cycle/crop detail, dashboard |
| Egg collection / milk record / reverse | records, **`GET /inventory/output-balances`** (available eggs/milk), movements, dashboard — production totals and available stock are different numbers |
| Put eggs into incubation (`consume_egg_stock`) / edit eggs set / cancel with return | breeding project (`egg_stock`), `GET /inventory/output-balances`, movements |
| Donation / spoilage / internal use of eggs, milk or feed | the item or `GET /inventory/output-balances`, movements |
| Stock-in/out/adjust/transfer/reverse | item (`stock`), lots, movements, medicines list (if medicine), dashboard `low_stock` |
| Health record / reverse | health records, withdrawals, the medicine (stock by lot), dashboard |
| Breeding outcome / reverse | breeding project (+milestones), cycle population, dashboard |
| Purchase / cancel | purchase, inventory items, finance summary/transactions, dashboard |
| Expense/income/reverse | finance transactions + summary, dashboard |
| Sale / cancel | sale, inventory (stock lines), the cycle (livestock lines), sales list, dashboard (income is NOT affected yet) |
| Invoice issue/void | sale (`payment_status`), invoice, receivables |
| Payment / reverse | invoice, sale (`payment_status`, `outstanding`), payments, **finance summary** (income), dashboard |
| Task create/complete/cancel | tasks list, calendar, dashboard work block |
| Template apply | tasks, schedules, calendar |
| Role change / removal / invitation | members, invitations, subscription usage (`team_members`) |

Use a cache-key convention (`['farm', farmId, 'cycles', id]`) so invalidation by prefix is trivial.

## 19. File downloads and uploads

* **Downloads** (invoice PDF, export file, record attachment) need the session cookie and are `Content-Disposition` responses. Fetch with credentials as a Blob, then save/open; do not point an `<a href>` at another origin.

```ts
const res = await api.get(`/invoices/${id}/pdf`, { responseType: 'blob' });
const url = URL.createObjectURL(res.data); window.open(url); // or a[download]
```

* On a failed download the body is JSON (`export_not_ready`, `export_expired`, …) — with `responseType: 'blob'` parse `await err.response.data.text()`.
* **Uploads**: attachments are `multipart/form-data` with field `file` (jpg, jpeg, png, webp, pdf, csv, txt, xls, xlsx; max 10 MiB; max 10 per record). Do not set `Content-Type` manually (let the browser add the boundary). `download_url` in the response is an authenticated API URL, not a public link.

## 20. Queued exports

`POST /reports/exports {report, format, filters, idempotency_key}` → `202` with `status: "queued"`. Show "preparing…", poll `GET /reports/exports/{id}` with backoff (2s, 5s, 10s … up to ~1 minute) until `completed` (then `GET …/download`) or `failed` (`error_code`, `error_message`; request a new export). Optionally react to the `export_ready`/`export_failed` notification instead of polling. Requires `report.export` and the `data_export` plan feature (`403 feature_not_available`). Files expire after 7 days (`410 export_expired`); only the requesting user can download. Locally, a queue worker (`php artisan queue:work`) must be running.

## 21. Notifications

`GET /notifications?unread=1` for the bell (`meta.unread_count`), `POST /notifications/{id}/read`, `POST /notifications/read-all`; preferences via `GET/PATCH /notification-preferences`. They are generated by a scheduled job, not by API calls: poll the list every few minutes while the app is open (there is no push/websocket in V1). Notifications point at their source (`source {type, id, reference}`) — link to the right screen; deleted/closed sources may 404/409.

## 22. Platform Admin separation

Show the admin area only when `data.user.platform_role` is `admin` or `support`; keep it a separate route tree and API client section (`/platform-admin/*`, no `X-Farm-Id`). A farm Owner is not a platform admin and a platform admin is not a farm Owner. `support` must see read-only screens (writes are `403 platform_write_forbidden`). Never expose platform tools inside the farm UI.

## 23. Local development checklist

1. `php artisan serve` (API on `http://localhost:8000`), frontend on `http://localhost:5173` or `:3000`.
2. `.env`: `SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000`, `CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:3000`, `SESSION_DRIVER=database`, `MAIL_MAILER=log` (read OTP codes in `storage/logs/laravel.log`), `FRONTEND_URL=http://localhost:5173` (invitation links), a queue worker for exports, `php artisan notifications:generate` to create notifications.
3. First request: `GET /auth/csrf-cookie`. If anything is `419`, you lost the cookie; if `401` on a logged-in session, the `Origin` is not in `SANCTUM_STATEFUL_DOMAINS`.
4. A platform admin: `php artisan platform:grant-admin you@example.com --role=admin`.
5. Use the Postman **99 — Workflows** to create realistic data before building list/detail screens.

## 24. Building V1 Product Workflows

Sections 1–23 explain *how to talk to the API*. This section explains *what to call next* for each product screen, so no one has to tell you verbally. Every statement was verified against the routes, FormRequests, services, resources and Postman collection (2026-10-06). Where the API still cannot tell the UI something, it is marked **GAP-xx** (§24.7); the frontend-handoff pass resolved GAP-01 … GAP-08 and left GAP-09 and the documentation notes open.

**The detailed, per-workflow orchestration (50 workflows, each with entry point, fetch, response properties, dependent dropdowns, conditional fields, final endpoint, side effects, "DO NOT also call…", refetch, permissions, error states and the Postman request) is in [`FRONTEND-WORKFLOWS.md`](FRONTEND-WORKFLOWS.md).** Read §24.1–§24.4 here first; they are the rules every workflow follows.

### 24.1 The golden rules

1. **One real-world event, one write.** Most endpoints do several things in one transaction. The workflow tells you which endpoint *owns* the event; never repeat any part of it elsewhere (see §24.5).
2. **Discover, don't hard-code.** Reasons, record types, capabilities, units, categories and permissions come from the API. Branch on `code`, `category`, `tracking_model`, capability codes; never on a `name`.
3. **Metadata decides what is shown; permissions and entitlements decide whether it is enabled.** Four independent layers: *farm operation* (hint), *species capability* (hard gate on record types and breeding workflows), *RBAC permission* (`GET /farm → membership.permissions`), *plan entitlement* (`GET /subscription/entitlements`).
4. **Stock and production are different numbers.** "Eggs collected" is a production total (records, dashboard `eggs_today`, reports); "eggs available" is `GET /inventory/output-balances` (the movement ledger).
5. **Never write derived numbers**: population, stock, balances, outstanding. They only change through the owning event.
6. **A task is work that should happen; a record is what happened.** Completing a task never creates a record: save the record first, then complete the task with it as evidence.

### 24.2 Metadata ladder — when to call what, and what it drives

| Call | When | Read | It drives |
|---|---|---|---|
| `GET /farm` | after `/auth/me` says `none`; after role change | `membership.permissions`, `timezone`, `currency` | which buttons/routes exist; "today" |
| `GET /subscription/entitlements` | startup; on `403 feature_not_available` / `409 plan_limit_reached` | `features`, `limits` | upsell/disable for cycles, invitations, advanced reports, exports |
| `GET /master/farm-operations` (+ `GET /farm/operations`) | production-type pickers, cycle creation | `category`, `tracking_model`, `selected`, `available` | ordering/de-emphasis only — **not enforced** by the backend |
| `GET /master/species` | once per farm; cycle creation; every record-type decision | `capability_codes`, `operation` | which record types, breeding workflows and forms a livestock cycle gets |
| `GET /master/species/{id}/capabilities` | breeding forms | `enabled`, `reference` (incubation/gestation days) | whether Incubation/Pregnancy is offered, expected dates |
| `GET /production-cycles/{id}` | when "Record activity" opens for a cycle | `available_record_types[]` = `{type, permissions_required}` | exactly the types `POST /records` accepts for this cycle now (cycle kind + species capability + open cycle) — no client-side intersection |
| `GET /master/record-types` | when "Record activity" opens (once, cached) | `type`, `permissions_required`, `inventory`, `measurement`, `area_fields`, `fields` | labels/forms for the types the cycle offered, and which permissions each needs |
| `GET /record-types/{type}/schema` (**not** under `/master`) | when a type is chosen (or reuse the list row) | `fields`, `measurement`, `inventory` | the form inputs under `details.*`, the quantity control, whether and how a stock section appears |
| `GET /master/units?dimension=` | every quantity field | units of one dimension | the unit dropdown; pre-select from `GET /settings/units` |
| `GET /master/inventory-options` | opening any stock, feed, egg or milk screen (cached) | `reasons.by_item_kind.<feed|eggs|milk|general>.{in,out}`, `sellable_categories`, `categories` | the list of actions (labels), and for each: **which endpoint** to call (`route`) |
| `GET /inventory/output-balances` | any egg/milk screen, before sale/incubation | `eggs|milk.{exists, inventory_item_id, available, by_storage_location}` | available stock, which store, the item id for sales and package conversions |
| `GET /master/health-record-types` | health form | `fields`, `medicines` (`required`/`forbidden`) | medicine-lines section |
| `GET /master/task-categories`, `GET /master/location-types`, `GET /finance/categories`, `GET /reports`, `GET /roles` | the respective form | codes / flags | dropdowns, report visibility (`relevant`, `available`, `exportable`), assignable roles |
| `GET /dashboard` | landing | `sections`, `operations.relevant`, `quick_record`, `quick_add`, `empty_state` | which blocks exist |

**Record-type ladder:** open the cycle detail → `data.available_record_types[]` is the list to offer (the backend applies the same cycle-kind, species-capability and open-cycle rules as `POST /records`; a closed cycle returns `[]`). For each entry, enable the type when `membership.permissions` contains every code in `permissions_required.always` (and `when_inventory_linked` when the form links stock; `when_correcting` when replacing a reversed record). Labels, measurement and the stock section come from `GET /master/record-types` rows (match on `type`). A type the cycle did not list is `422` on `type`.

**Stock ladder (Feed, Eggs, Milk, general):**

```text
GET /master/inventory-options
  → reasons.by_item_kind.<feed|eggs|milk|general>.in[] / out[]       (use this, not the flat reasons.in/out)
  → the user picks an entry (code, label)
  → entry.route.kind / method / url            (url is absolute /api/v1/…; path = the same route relative to /api/v1)
       inventory        → POST /inventory/stock-in | /inventory/stock-out     (entry.manual === true)
       record           → POST /records, type = route.record_type              (egg_collection | milk | feed_use)
       breeding_project → POST /breeding-projects, consume_egg_stock          (incubation)
       sale             → POST /sales, a stock line
       transfer / adjustment → POST /inventory/transfers | /inventory/adjustments
  → entry.creates_operational_record, entry.also[] (purchase books the expense too)
```

### 24.3 How to know the item kind of what the user is acting on

Every inventory item (`GET /inventory/items`, `GET /inventory/items/{id}`) carries `kind` (`feed` | `eggs` | `milk` | `general`) and `is_system_managed` (`true` for the farm's automatic Eggs / Milk items). Use `kind` to index `reasons.by_item_kind`; never infer it from a name or category. Egg and milk screens do not need an item picker at all: use `output: "eggs"|"milk"` on stock-in/out, sales and purchases. Details: [`FRONTEND-WORKFLOWS.md` §0.6](FRONTEND-WORKFLOWS.md#06-item-kind-of-a-stock-item-decides-which-inventory-options-list-to-render).

### 24.4 Orientation map

| User action | Discover with | UI driven by | Submit through | Backend automatically affects |
|---|---|---|---|---|
| Start livestock batch | `/master/farm-operations`, `/master/species`, `…/breeds` | `tracking_model`, species `capability_codes` | `POST /production-cycles` | cycle `BAT-…`, opening population +N, `active_cycles` limit |
| Start crop project | `/master/crops`, `…/varieties`, `/master/planting-reference` | material vs unit types | `POST /production-cycles` | cycle `CRP-…`, baseline units; no stock |
| Record activity (any) | cycle + `/master/record-types` + `/master/species` | `cycle_kind`, `capability`, `permission` | `POST /records` | record (+ population or stock effect for some types) |
| Mortality | `/record-types/mortality/schema` | `supports_mortality` | `POST /records` | population −N |
| Population adjustment | `GET /production-cycles/{id}` | `current_population` = `expected_population` | `POST /records` | population `actual − expected` |
| Weight / temperature / water | schema + `/master/units?dimension=` | `measurement.dimension` | `POST /records` | record only |
| Feed IN | `/master/inventory-options` → `feed.in` | `route`, `manual` | `POST /inventory/stock-in` (or `POST /purchases`) | one stock-in (purchase also books expense) |
| Feed OUT (not livestock) | `feed.out` | `route` | `POST /inventory/stock-out` | one stock-out |
| Feed used for livestock | `feed.out → production_use`, feed items | `route.record_type: feed_use` | `POST /records` `feed_use` + `details.inventory` | record **and** stock-out `production_use` |
| Eggs produced on farm | `eggs.in → production`, `/inventory/output-balances` | `route.kind: record`, `produces_eggs` | `POST /records` `egg_collection` | record **and** stock-in `production`; creates Eggs item / Main Store |
| Eggs donation / received / other | `eggs.in` | `manual: true` | `POST /inventory/stock-in {output:"eggs"}` | stock-in only, never a record |
| Eggs purchase (with cost) | `eggs.in → purchase` (`also[]`) | `output` | `POST /purchases` stock line `{output:"eggs"}` | stock-in + expense; item and Main Store resolved/created |
| Eggs damaged / spoiled / internal / donated out | `eggs.out` | `manual: true` | `POST /inventory/stock-out {output:"eggs"}` | stock-out only |
| Egg sale | `output-balances.eggs.available` | `exists`, `available` | `POST /sales` stock line `{output:"eggs"}` (or `inventory_item_id`) | sale + stock-out `sale`; no income until paid; never creates stock |
| Egg incubation | `eggs.out → incubation`, `supports_incubation` | `route.kind: breeding_project` | `POST /breeding-projects` `consume_egg_stock` | project **and** stock-out `incubation` |
| Milk produced / IN / OUT / sale | `by_item_kind.milk`, `produces_milk` | same as eggs | as eggs with `type: milk`, `output:"milk"` | as eggs |
| Planting / fertilizer / harvest | schema `inventory_*` | `inventory_direction`, `inventory_dimensions` | `POST /records` (+ `details.inventory`) | record + stock out (planting, fertilizer, pesticide) or in (harvest) |
| General stock IN/OUT | `general.in/out` | `manual` | `/inventory/stock-in|stock-out` | one movement |
| Transfer / count correction | item `balances[]` | store/lot | `/inventory/transfers`, `/inventory/adjustments` | two linked movements / one signed adjustment |
| Health record | `/master/health-record-types` | `medicines: required|forbidden` | `POST /health-records` | record + stock-out per medicine line + withdrawal window |
| Breeding project / outcome | species capabilities | `supports_incubation|pregnancy` | `POST /breeding-projects`, `…/outcomes` | project; outcome → one record + population +N |
| Task → done | `GET /tasks/{id}/record-prefill` | `linked_record_type`, `requires_evidence` | record endpoint, **then** `POST /tasks/{id}/complete` | completion links evidence only |
| Purchase | contacts, categories, items | line kinds | `POST /purchases` | stock-in per line + one expense |
| Sale → invoice → payment | contacts, items/balances | `sellable_categories`, `payment_status` | `POST /sales` → `POST /sales/{id}/invoice` → `POST /invoices/{id}/payments` | stock/population exit → snapshot → income row |
| Report / export | `GET /reports` | `relevant`, `available`, `exportable` | `GET /reports/{code}`, `POST /reports/exports` | background file |

### 24.5 "DO NOT also call…" (double-write hazards)

| After this success… | …do NOT call | Because |
|---|---|---|
| `POST /records` `egg_collection` / `milk` | `POST /inventory/stock-in` | the record already wrote the stock-in (a second call counts the eggs/milk twice) |
| `POST /records` `feed_use` with `details.inventory` | `POST /inventory/stock-out` | the record already wrote the `production_use` stock-out |
| `POST /records` `planting` / `fertilizer_application` / `pesticide_application` with `details.inventory` | `POST /inventory/stock-out` | same |
| `POST /records` `crop_harvest` with `details.inventory` | `POST /inventory/stock-in` | same |
| `POST /breeding-projects` with `consume_egg_stock` | `POST /inventory/stock-out` (refused anyway) | the project already took the eggs |
| `POST /breeding-projects/{id}/outcomes` | a population adjustment or egg stock-in | the outcome wrote the population record; eggs left stock when set |
| `POST /sales` | `POST /inventory/stock-out`, `POST /income`, population edits | the sale wrote the stock/population exit; income arrives with payments |
| `POST /sales` with an `invoice` block | `POST /sales/{id}/invoice` | invoice already issued (`409 invoice_exists`) |
| `POST /invoices/{id}/payments` | `POST /income` | the payment already booked the income |
| `POST /purchases` (stock lines) | `POST /inventory/stock-in`, `POST /expenses` | the purchase wrote stock and the expense (`409 finance_already_recorded`) |
| `POST /health-records` with medicine lines | `POST /inventory/stock-out` for the medicine | one stock-out per line already written |
| `POST /tasks/{id}/complete` | expecting a record | completion creates nothing; record first |
| Donated/purchased/received eggs or milk | `POST /records` `egg_collection`/`milk` | those mean "produced by this farm's livestock" |

### 24.6 What to refetch

The table in §18 is authoritative. Additions for egg/milk screens: always refetch `GET /inventory/output-balances` and `GET /inventory/movements?inventory_item_id=`; the dashboard's `eggs_today` is production only.

### 24.7 GAP index

The frontend-handoff correction pass resolved GAP-01 … GAP-08 in the API (no workaround is needed any more). Only the items marked **open** remain.

| ID | Was | Status / what the contract says now |
|---|---|---|
| GAP-01 | `record-type.permission` listed only `record.create`/`record.adjust`; `inventory.use` was hidden | **Resolved.** `permissions_required: {always, when_inventory_linked, when_correcting}` on every record schema; the legacy `permission` is unchanged |
| GAP-02 | no endpoint returned the record types valid for a cycle | **Resolved.** `GET /production-cycles/{id}` (and `/summary`) → `available_record_types[]`, produced by the same rules as record creation; not on lists or write responses |
| GAP-03 | inventory items exposed no `kind` / `system_key` | **Resolved.** `kind` (`feed|eggs|milk|general`) and `is_system_managed` on every item |
| GAP-04 | record schema did not describe stock/output integration | **Resolved.** `inventory` block (`mode`, `direction`, `output`, `item_category`, `dimensions`, `fields`), `area_fields`; the compound `components`/`context` shapes stay documented in `FRONTEND-WORKFLOWS.md` §0.4 and Postman by design |
| GAP-05 | flat `reasons.in/out` said `production.manual = true` for eggs/milk too | **Resolved additively.** `by_item_kind` stays authoritative; flat entries gain `manual_for_kinds`; `reasons.flat_lists.deprecated = true` |
| GAP-06 | metadata routes were relative in some places and absolute in others | **Resolved additively.** `url` (absolute `/api/v1/…`) on every metadata route; `path` keeps its relative meaning; `endpoint` stays absolute and gains `url` + `path` on `record-prefill` and `quick_add[]` |
| GAP-07 | no way to list the farms of a user | **Resolved.** `GET /auth/me → data.farms[] = {id, name, role}` (active memberships); select with `X-Farm-Id`; permissions stay on `GET /farm` |
| GAP-08 | sale/purchase lines needed an item id that is `null` until stock exists | **Resolved.** `items[].output: eggs|milk` instead of `inventory_item_id` (exactly one); sale never creates stock, purchase resolves/creates it |
| GAP-09 | the dashboard has `eggs_today` (production) but no milk-produced KPI and no available eggs/milk KPI | **Open (intentionally not implemented).** Use `GET /records?type=milk`, reports and `GET /inventory/output-balances` |
| GAP-P1 … P4 | Postman had no milk, feed/egg IN-reason, egg/milk OUT-reason or output-sale examples | **Resolved.** New requests + saved examples (see `docs/postman/COVERAGE.md`) |
| GAP-P5 | Flow 14 was not executed | **Resolved.** Flow 14 (22 requests) was executed with Newman against the final behaviour: 0 failures |

Also noted: farm operation selection is advisory (a cycle can be created for an unselected operation); `dashboard.quick_record` is capped at 6 types; the `GET /settings/package-conversions` description lists only `crop_type|custom` for `context_type` although `inventory_item` is accepted; the member-role endpoint descriptions omit `vet`, which is accepted.
