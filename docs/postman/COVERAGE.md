# Postman package coverage report

Package: `docs/postman/Farm-Management-API.postman_collection.json` (Postman Collection v2.1), `docs/postman/Farm-Management-Local.postman_environment.json`, `docs/postman/WORKFLOWS.md`, with the contract in `docs/api/API-CONTRACT.md` and `docs/api/FRONTEND-INTEGRATION.md`.

## 1. Summary

| Metric | Value |
|---|---|
| Laravel API routes (`/api/v1`, `php artisan route:list --path=api/v1`) | **232** (GET 120, PATCH 23, PUT 9, POST 78, DELETE 2; `HEAD` aliases of `GET` not counted) |
| Routes intentionally excluded | 0 of the `/api/v1` routes (see §2 for the 7 non-API framework routes) |
| Postman requests in the reference folders (00-22, 90) | **264** |
| Postman requests inside workflows (99) | 148 (copies of reference requests, same bodies and scripts) |
| Total Postman requests | 412 |
| Endpoint coverage (distinct method+route in the reference folders) | **232 / 232 = 100.0 %** (Platform Admin routes: 35, all included in folder 90) |
| Requests with a saved success example | 261 |
| Requests with at least one saved error example | 74 |
| Saved example responses (all real) | 376 |
| Workflows | **14** (Flows 1-13 plus Flow S, the demo-farm setup) = 148 requests |
| Environment variables | 98 |
| Undocumented endpoints | **none** |

All saved responses were produced by executing the scenario against the real application (a throwaway harness drove the Laravel test client as the real authenticated users; 424 requests including 114 error/alternate probes) — none was written by hand. The workflows were additionally executed in their own order on a fresh database (148 requests, 0 failures).

## 2. Routes and exclusions

All 232 `/api/v1` routes are in the collection. The seven routes outside `/api/v1` were deliberately not documented because they are not part of the frontend API:

| Route | Why excluded |
|---|---|
| `GET /` | Laravel welcome page (web) |
| `GET /up` | Laravel framework health check (the API health endpoint is `GET /api/v1/health`) |
| `GET /docs/api`, `GET /docs/api.json`, `GET /_scramble/dev-tools/devtools.js` | Scramble API documentation UI, available only with `APP_ENV=local` |
| `GET\|PUT /storage/{path}` | Laravel's local-disk file-serving/upload helper (`storage.local`): signed-URL infrastructure, not an application endpoint |

Phase 20 (WhatsApp / AI parsing) is deferred and has no routes. The word *WhatsApp* appears in the package only in (a) the Platform Admin setting key `support_whatsapp` (a real V1 setting holding a support phone number) and (b) explicit statements that WhatsApp is not part of V1.

## 3. Modules covered

| Folder | Requests | Distinct endpoints | With a success example | With an error example |
|---|---:|---:|---:|---:|
| 00 — Setup & Health | 1 | 1 | 1 | 0 |
| 01 — Authentication | 11 | 11 | 11 | 9 |
| 02 — Onboarding & Account | 4 | 4 | 4 | 3 |
| 03 — Farms, Team & Access | 12 | 12 | 12 | 6 |
| 04 — Plans & Subscription | 6 | 6 | 6 | 2 |
| 05 — Master Data | 15 | 15 | 15 | 1 |
| 06 — Measurements & Units | 12 | 11 | 12 | 4 |
| 07 — Locations & Farm Structure | 13 | 13 | 13 | 1 |
| 08 — Production Cycles | 9 | 8 | 9 | 3 |
| 09 — Operational Records | 16 | 8 | 16 | 5 |
| 10 — Inventory | 21 | 18 | 21 | 6 |
| 11 — Health | 10 | 9 | 10 | 2 |
| 12 — Breeding | 9 | 9 | 9 | 1 |
| 13 — Tasks, Templates & Calendar | 20 | 20 | 20 | 5 |
| 14 — Crop Operations | 12 | 2 | 12 | 2 |
| 15 — Contacts & Purchasing | 9 | 8 | 9 | 3 |
| 16 — Finance | 8 | 8 | 8 | 2 |
| 17 — Sales, Invoices & Payments | 15 | 14 | 15 | 6 |
| 18 — Dashboard & Insights | 3 | 3 | 3 | 0 |
| 19 — Reports & Exports | 11 | 6 | 11 | 3 |
| 20 — Notifications | 7 | 7 | 7 | 0 |
| 21 — Audit | 1 | 1 | 1 | 1 |
| 22 — Localization & Preferences | 4 | 4 | 4 | 2 |
| 90 — Platform Admin | 35 | 35 | 35 | 7 |

## 4. Verification performed

| Check | Result |
|---|---|
| Collection and environment parse as JSON | pass |
| Collection validates against the **official Postman Collection v2.1.0 JSON Schema** (`schema.getpostman.com`, checked with Ajv draft-04) | pass (0 errors) |
| Every request URL resolves to an existing route with the right HTTP method | pass (0 nonexistent routes) |
| Every route exists in the reference folders | pass (232 / 232) |
| Request bodies vs the OpenAPI/FormRequest schemas | 123 bodies checked: every key exists, every required key present (one known OpenAPI discrepancy, D1). Every body was also **executed** against the real application and accepted with the documented status |
| Saved examples vs real resources | every example is a captured real response; all 376 parse and carry the success or error envelope |
| Variable-capture scripts | 156 capture paths evaluated against the real responses of the master run and of the flow run: all found |
| Variable provenance | statically: every `{{variable}}` used is an environment input, a per-request dynamic value, a pre-request variable or captured by some request; dynamically: the flows (in their folder order) ran end to end on a fresh database, so every variable was already set when used |
| No credentials or secrets | no bearer tokens, API keys, JWT/session cookies or literal OTPs; the only emails are `@example.*`; the single reset token in a response is replaced by `<single-use reset token>`; environment passwords are placeholders (`Example-Passw0rd`, `Example-NewPassw0rd`), `admin_password`, `otp_code`, `invitation_token`, `reset_token` are empty |
| Phase 20 not documented | pass (see §2) |
| Live Sanctum check over HTTP (built-in PHP server against the disposable test DB) | pass: csrf cookie, stateful Origin requirement (`400`/`401` without it), CSRF `419`, register/verify/onboard/me/farm/logout, CORS preflight |
| OpenAPI / documentation tests | see §10 |
| `git diff --check` | see §10 |

## 5. Discrepancies discovered between OpenAPI, docs and code

The collection and contract document the **running code**; these differences exist in the older artefacts.

* **D1 — `POST /sales/{sale}/invoice`**: OpenAPI marks `sale_id` as a required body field; the route parameter supplies it and the call succeeds without it (verified, `201`). `POST /invoices` takes `sale_id` in the body.
* **D2 — Success status/shape in OpenAPI** (generated from return types): the endpoints below are documented with a different 2xx status than they really return.

| Operation | Documented 2xx | Actual |
|---|---|---|
| `POST /inventory/transfers` | 200 | 201 |
| `POST /inventory/movements/{movement}/reverse` | 200 | 201 |

  OpenAPI also types the `data` of the following responses differently from what the API returns (generated clients should not trust them):

| Operation | OpenAPI says | Reality |
|---|---|---|
| `POST /invitations/accept` | data: array, meta: string | data: object, meta: object |
| `PATCH /inventory/items/{item}` | data: array, meta: string | data: object, meta: object |
| `PATCH /feed-formulas/{formula}` | data: array, meta: string | data: object, meta: object |
| `PUT /health/medicines/{item}/profile` | data: array, meta: string | data: object, meta: object |
| `PATCH /breeding-projects/{project}` | data: array, meta: string | data: object, meta: object |
| `POST /breeding-projects/{project}/cancel` | data: array, meta: string | data: object, meta: object |
| `PATCH /work-templates/{template}` | data: array, meta: string | data: object, meta: object |
| `PATCH /tasks/{task}` | data: array, meta: string | data: object, meta: object |
| `POST /tasks/{task}/complete` | data: array, meta: string | data: object, meta: object |
| `POST /tasks/{task}/cancel` | data: array, meta: string | data: object, meta: object |
| `POST /schedules/{schedule}/end` | data: array, meta: string | data: object, meta: object |
| `PATCH /contacts/{contact}` | data: array, meta: string | data: object, meta: object |
| `DELETE /farm/members/{membership}` | data: unspecified, meta: string | data: null, meta: object |
| `POST /platform-admin/users/{user}/suspend` | data: array | data: object, meta: object |
| `POST /platform-admin/users/{user}/restore` | data: array | data: object, meta: object |
| `POST /platform-admin/master/{kind}` | data: array | data: object, meta: object |
| `GET /platform-admin/master/{kind}/{id}` | data: array | data: object, meta: object |
| `PATCH /platform-admin/master/{kind}/{id}` | data: array | data: object, meta: object |
| `POST /platform-admin/work-templates` | data: array | data: object, meta: object |
| `GET /platform-admin/work-templates/{template}` | data: array | data: object, meta: object |
| `PATCH /platform-admin/work-templates/{template}` | data: array | data: object, meta: object |
| `POST /platform-admin/work-templates/{template}/publish` | data: array | data: object, meta: object |
| `POST /platform-admin/work-templates/{template}/archive` | data: array | data: object, meta: object |
| `GET /platform-admin/users/{user}` | data: array | data: object, meta: object |
| `PUT /account/password` | data: unspecified, meta: string | data: null, meta: object |
* **D3 — Boolean query parameters**: Phase docs say `true`/`false` (for example `GET /notifications?unread=true`). Laravel's `boolean` rule rejects those strings: `unread=true` and `include_inactive=true` return `422`. Send `1`/`0`. Only the master-data lists (`available`, `include_inactive`) also accept `true`/`false`.
* **D4 — `docs/api/README.md` role section is outdated**: it lists four roles and a short permission table. The code has five roles (`vet` exists) and 58 permissions (matrix in `API-CONTRACT.md` §5.3).
* **D5 — `docs/api/PHASE-19-LOCALIZATION.md`** refers to `/measurements/unit-preferences`; the real route is `GET|PUT /settings/units`.
* **D6 — Blueprint vs implementation** (`docs/implementations/04-API-CONVENTIONS.md` lists proposed contracts): these do **not** exist as written —
  `GET /me` (real: `GET /auth/me`), `POST /auth/email/otp/{resend,verify}` (real: `/auth/email/{resend,verify}`), `POST /auth/forgot-password` and `/auth/reset-password` (real: `/auth/password/{forgot,verify-otp,reset}`), `GET /onboarding/status` (use `next_action`), `GET /permissions` (not built; `GET /roles` returns role permission lists), `GET|PATCH /farm/settings`, `DELETE` on places/custom breeds/schedules/templates (no DELETE endpoints exist; deactivate), `/inventory/medicines` (real: `/health/medicines`), `POST /production-cycles/{cycle}/apply-template` (real: `POST /work-templates/{template}/apply`), `POST /invoices/{invoice}/send` (delivery not built), `PUT /settings/package-conversions` (real: POST create + PATCH update). The blueprint also makes "Farm Operations" part of mandatory onboarding; the implementation asks for the **farm name only** (operations are an optional later preference).
* **D7 — OpenAPI request schemas list server-owned fields** (`farm_id`, `created_by`, `currency`, `entry_type`, `reference`, `total_amount`, `status`, …) as properties. They are `missing` rules: sending them returns `422`. The Postman descriptions list them under "NOT accepted".
* **D8 — `GET /records?type=`**: the documented filter enum is the writable record types plus `reversal`/`breeding_outcome`; the system-generated `livestock_sale` record type that appears in the audit trail is rejected with 422 (not in the documented filter enum).
* **D9 — Export create returns two documented statuses**: `202` for a new export and `200` for an idempotent replay (both documented; the collection asserts either).

## 6. Identifier findings (UUID vs integer)

* **No public resource uses an integer id.** Every domain model uses a UUIDv7 primary key (`HasUuidV7`), including users, farms, memberships, places (locations, production areas, storage locations), records, movements, tasks and every ledger. `UuidV7ConventionTest` enforces it.
* Human-readable references exist for: production cycles (`BAT-`, `CRP-`), breeding projects (`BRD-`), tasks (`TSK-`), purchases (`PUR-`), finance transactions (`FIN-`), sales (`SAL-`), invoices (`INV-`), payments (`PAY-`) as `PREFIX-YYYY-#####`. They are display codes: no endpoint accepts a reference instead of the UUID.
* Non-UUID identifiers in URLs/bodies are catalogue codes by design: unit codes (`kg`), record types (`mortality`), report codes (`income_expense`), locale codes (`en`), platform setting keys, feature flag keys and master `kind` slugs.
* The audit trail uses composite string ids (`record:<uuid>`).
* `GET /farm/members/{id}` takes the membership id, not the user id.
* Full table: `API-CONTRACT.md` §6.

## 7. Roles and permissions findings

* Five farm roles: `owner`, `manager`, `farm_worker`, `finance`, `vet`; 58 permission strings (one reserved: `livestock.batch.create`, granted to owner/manager, used by no route).
* There is **no `GET /permissions`**. The frontend gets the caller's permission list from `GET /farm` (`membership.permissions`), and every role preset's list from `GET /roles` (needs `team.view`). `GET /auth/me` returns only `farm.role` and `user.platform_role` — **no permissions**.
* Requests never carry permission strings; each endpoint's required permission is middleware (`farm.permission:<perm>`) and is stated in every request description.
* Team rules: Owner assigns manager/farm_worker/finance/vet; Manager only farm_worker/finance/vet; nobody assigns `owner`, changes their own role or removes the last owner.
* Platform Admin (`admin` read+write, `support` read-only) is a separate, operator-granted system with no farm context; farm roles confer nothing there.
* RBAC and plan entitlements are independent (`403 forbidden` vs `403 feature_not_available` / `409 plan_limit_reached`).

## 8. Authentication and farm-context findings

* Sanctum first-party SPA cookie auth; **no bearer tokens** are issued by any endpoint. Required: CSRF cookie + `X-XSRF-TOKEN` on writes + a stateful `Origin` on **every** request (an existing session cookie without it gets `401`; register without it `400 stateful_request_required`; missing CSRF `419 session_expired`). Verified over real HTTP.
* Session cookie `farm_management_api_session` (HttpOnly, SameSite=Lax, 120 min); `XSRF-TOKEN` readable.
* `next_action` ∈ `verify_email`, `complete_farm_setup`, `no_active_farm`, `none` (computed in that order). `onboarded` is never cleared.
* Farm context: oldest ACTIVE membership by default, optional `X-Farm-Id` header (`403 farm_access_denied` for a farm you do not actively belong to, `403 no_active_farm` when none). No switch endpoint and no "list my farms" endpoint.
* OTP/invitation tokens exist only in email; locally they are in `storage/logs/laravel.log`.
* A suspended account gets `403 account_suspended` on every request and its session is ended.

## 9. Frontend Integration Gaps

None of these blocks single-farm frontend development; they were **not** fixed (documentation task only).

| # | Missing capability | Affected frontend workflow | Recommended backend change | Severity |
|---|---|---|---|---|
| G1 | No endpoint lists the farms a user belongs to and none switches the active farm; only the optional `X-Farm-Id` header exists | Farm switcher / multi-farm users; a user invited to a second farm cannot discover its id except from `meta.accepted_farm_id` | Add `GET /farms` (my active memberships: id, name, role) or include `farms[]` in the auth state; keep `X-Farm-Id` for selection | Medium (blocking only if multi-farm UX is in V1 scope) |
| G2 | `GET /auth/me` does not return `membership.permissions` | App startup needs two calls (`/auth/me` then `/farm`) | Include `farm.permissions` in the auth state, or keep the documented two-step startup | Low |
| G3 | No `GET /permissions` catalogue (blueprint listed it) | Permission-matrix admin screens | Only needed if a role editor is planned; `GET /roles` already returns the preset lists | Low |
| G4 | Boolean query parameters accept `true`/`false` on master-data lists only; elsewhere only `1`/`0` | Filters/toggles; docs and generated clients disagree | Normalise booleans in a shared FormRequest trait | Low (documented) |
| G5 | Notifications are pull-only (generated hourly, no push/websocket) | Real-time bell | Acceptable for V1; add polling guidance (done) or SSE later | Low |
| G6 | OpenAPI response statuses/types are inaccurate for several action endpoints (D2) | Generated TypeScript clients | Fix Scramble annotations (`@response` / `#[Response]`) and re-export `docs/api/openapi.json` | Low-Medium |
| G7 | Invitation link/token is only emailed; the API never returns it | Owner cannot copy/share an invite link in the UI | By design (token never stored/returned); only change if a share-link feature is required | Low |
| G8 | Exports and notifications depend on a running queue worker and scheduler | Local setup, "export stays queued" | Document in the runbook (done in the integration guide) | Info |
| G9 | No server-side `sort` on farm list endpoints | Sortable tables | Add `sort`/`direction` where needed; clients sort the current page meanwhile | Low |

## 10. OpenAPI and documentation checks

* `php artisan scramble:export` regenerated from the current code is **byte-identical** to the committed `docs/api/openapi.json` (183 paths): the spec is current; its inaccuracies are listed in D1/D2/D7.
* Documentation/OpenAPI tests (`ApiDocumentationTest`, `ApiErrorFormatTest`, `UuidV7ConventionTest`, `HealthEndpointTest`): **26 tests, 684 assertions, all passing**.
* Full suite (informational, nothing in the application was changed): 823 tests, 11,465 assertions, **4 failures**, all clock-boundary tests that compare the UTC day with the Lagos day (`BreedingTest::test_50_eggs_expected_40_actual_37_adds_exactly_37_once`, `CropOperationsTest::test_harvest_lots_and_receiving_location_rules`, `FinanceTest::test_package_conversion_lots_and_expiry_flow_through_the_purchase`, `InventoryTest::test_movement_filters_pagination_and_validation`). They ran at 23:xx UTC / 00:xx Lagos, when "today" differs between the two zones; this is an existing clock sensitivity unrelated to this task.
* `git diff --check`: clean (tracked changes and every new file).


## 11. Keeping the package current

* The collection, saved responses and docs were generated from a scenario that was executed against a disposable `farm_management_test` database by a throwaway harness (a PHPUnit file that drives the Laravel test client). The harness is **not** committed; no application code, route, migration or test was changed.
* When an endpoint changes: edit the affected Postman request (body, Tests capture, description), re-send it (or re-run its flow) against a development server to refresh the saved example, re-export the OpenAPI file (`php artisan scramble:export --path=docs/api/openapi.json`; it needs `memory_limit` of about 1 GB and takes a couple of minutes) and update this report.
* The workflows are the regression check for the package: run **99 — Workflows** in order on a fresh database after any API change; every request carries Tests assertions on its status.

## Addendum — Feed, eggs and milk stock (2026-10-06)

The collection was extended for the one-event / one-entry stock work described in `docs/api/FEED-EGGS-MILK-STOCK.md`. Everything above remains true except the deltas below.

| Metric | Before | After |
|---|---|---|
| Laravel API routes (`/api/v1`) | 232 | **233** (`GET /inventory/output-balances`); 233 / 233 covered |
| Reference requests (folders 00-22, 90) | 264 | **273** (+9: output balances, donate eggs IN, give away eggs OUT, donate feed OUT, egg movement history, record milk, start incubation taking eggs, cancel incubation returning eggs, sell surplus feed) |
| Workflows | 14 (Flows 1-13 + S) | **15** (+ Flow 14 — Feed, eggs and milk stock, 11 requests) |
| Workflow requests | 148 | **159** |
| Total requests | 412 | **432** |
| Environment variables (values in the environment file) | 99 | **108** (`egg_item_id`, `milk_item_id`, `movement_egg_in_id`, `movement_egg_out_id`, `breeding_project_egg_id`, `sale_feed_id`, `record_milk_id`, `record_eggs_movement_id`, `dairy_cycle_id`; plus the existing set) |

* Saved responses of the nine new requests (and the refreshed `Inventory option catalogue` and `Record egg collection` examples) were produced by running the real scenario through the Laravel test client as the real authenticated users (not hand-written). The scenario is verified through real HTTP/API integration tests (`tests/Feature/Inventory/OutputStockTest.php`); Flow 14 is **prepared** but was **not** executed in Postman or the Postman runner. Its steps use the same variables and prerequisites as Flows 3 and 5.
* Behaviour changes that affect existing requests: `Record egg collection (compound quantity)` now also creates the stock-in (it sends `details.inventory.storage_location_id = {{store_id}}` because Flow S creates two stores); The feed examples of `Issue stock (stock-out)` now use reason `spoiled` (generic `use` is refused for feed items: `422`, use a `feed_use` record). `Issue stock (stock-out)` / `Receive stock (stock-in)` gained `output` and the new reason sets; a feed_use movement is now reason `production_use`; sale stock lines accept feed.
* New collection variable `date_today` (farm-local day) was added to the pre-request script for the incubation start date.
* The Flow 10 "sale of eggs" still sells the manual `Table Eggs` item from Flow S; the farm's own `Eggs` item (created by the first egg collection) is a separate produce item, so the existing flow is unaffected.
