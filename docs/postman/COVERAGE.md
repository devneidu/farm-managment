# Postman package coverage report

Package: `docs/postman/Farm-Management-API.postman_collection.json` (Postman Collection v2.1), `docs/postman/Farm-Management-Local.postman_environment.json`, `docs/postman/WORKFLOWS.md`, with the contract in `docs/api/API-CONTRACT.md` and `docs/api/FRONTEND-INTEGRATION.md`.

## 1. Summary

Figures below were measured on the final collection after the frontend-handoff correction pass (GAP-01 … GAP-08, see the addendum at the end); earlier versions of this report carried approximate counts.

| Metric | Value |
|---|---|
| Laravel API routes (`/api/v1`, `php artisan route:list --path=api/v1`) | **233** (GET 121, PATCH 23, PUT 9, POST 78, DELETE 2; `HEAD` aliases of `GET` not counted) |
| OpenAPI (`docs/api/openapi.json`) | **184 paths, 233 operations** |
| Routes intentionally excluded | 0 of the `/api/v1` routes (see §2 for the 7 non-API framework routes) |
| Postman requests in the reference folders (00-22, 90) | **283** |
| Postman requests inside workflows (99) | **170** (copies of reference requests plus the Flow 14 steps) |
| Total Postman requests | **453** |
| Endpoint coverage (distinct method+route in the reference folders, matched against `route:list`) | **233 / 233 = 100.0 %**; 0 requests point at an unknown route |
| Requests with a saved success example | 450 (of 453) |
| Requests with at least one saved error example | 94 |
| Saved example responses | 622 |
| Workflows | **14** (Flows 1-13 plus Flow S, the demo-farm setup) = 170 requests |
| Environment variables | **111** |
| Undocumented endpoints | **none** |

Saved responses come from executing the scenario against the real application (earlier packages: a throwaway Laravel-test-client harness; this pass: Newman against a live local server and real HTTP, driven by an uncommitted script). The only exceptions are seven Auth-state examples that were not re-executed (the reference-folder copies of register, verify email, login (two examples) and onboarding, plus `Accept a farm invitation` in folder 03 and in Flow 13): their `farms[]` field was derived mechanically from the example's own `farm` object (single-farm users), see the addendum. The workflows were executed in their own order on a fresh database: 170 requests, 361 assertions, 0 failures (addendum).

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
| 01 — Authentication | 11 | 11 | 10 | 9 |
| 02 — Onboarding & Account | 4 | 4 | 4 | 3 |
| 03 — Farms, Team & Access | 12 | 12 | 12 | 6 |
| 04 — Plans & Subscription | 6 | 6 | 4 | 2 |
| 05 — Master Data | 15 | 15 | 15 | 1 |
| 06 — Measurements & Units | 12 | 11 | 12 | 4 |
| 07 — Locations & Farm Structure | 13 | 13 | 13 | 1 |
| 08 — Production Cycles | 9 | 8 | 9 | 3 |
| 09 — Operational Records | 18 | 9 | 18 | 5 |
| 10 — Inventory | 32 | 19 | 32 | 12 |
| 11 — Health | 10 | 9 | 10 | 2 |
| 12 — Breeding | 11 | 9 | 11 | 2 |
| 13 — Tasks, Templates & Calendar | 20 | 20 | 20 | 5 |
| 14 — Crop Operations | 12 | 2 | 12 | 2 |
| 15 — Contacts & Purchasing | 10 | 8 | 10 | 4 |
| 16 — Finance | 8 | 8 | 8 | 2 |
| 17 — Sales, Invoices & Payments | 18 | 14 | 18 | 8 |
| 18 — Dashboard & Insights | 3 | 3 | 3 | 0 |
| 19 — Reports & Exports | 11 | 11 | 11 | 3 |
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
| Newman execution of every flow on the final collection (live server, fresh MySQL database) | pass: 170 requests, 361 assertions, 0 failures (addendum) |
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
* There is **no `GET /permissions`**. The frontend gets the caller's permission list from `GET /farm` (`membership.permissions`), and every role preset's list from `GET /roles` (needs `team.view`). `GET /auth/me` returns `farm.role`, `farms[]` (`{id, name, role}` per active membership) and `user.platform_role` — **no permissions** (by design: they stay on `GET /farm → membership.permissions`).
* Requests never carry permission strings; each endpoint's required permission is middleware (`farm.permission:<perm>`) and is stated in every request description.
* Team rules: Owner assigns manager/farm_worker/finance/vet; Manager only farm_worker/finance/vet; nobody assigns `owner`, changes their own role or removes the last owner.
* Platform Admin (`admin` read+write, `support` read-only) is a separate, operator-granted system with no farm context; farm roles confer nothing there.
* RBAC and plan entitlements are independent (`403 forbidden` vs `403 feature_not_available` / `409 plan_limit_reached`).

## 8. Authentication and farm-context findings

* Sanctum first-party SPA cookie auth; **no bearer tokens** are issued by any endpoint. Required: CSRF cookie + `X-XSRF-TOKEN` on writes + a stateful `Origin` on **every** request (an existing session cookie without it gets `401`; register without it `400 stateful_request_required`; missing CSRF `419 session_expired`). Verified over real HTTP.
* Session cookie `farm_management_api_session` (HttpOnly, SameSite=Lax, 120 min); `XSRF-TOKEN` readable.
* `next_action` ∈ `verify_email`, `marketplace`, `complete_farm_setup`, `no_active_farm`, `none` (computed in that order; `marketplace` only for a verified user with no active farm who belongs to a Marketplace shop — Phase 22 final review). `onboarded` is never cleared.
* Farm context: oldest ACTIVE membership by default, optional `X-Farm-Id` header (`403 farm_access_denied` for a farm you do not actively belong to, `403 no_active_farm` when none). `GET /auth/me → data.farms[]` lists the farms you actively belong to (GAP-07, resolved); there is still no switch endpoint (the header is the switch).
* OTP/invitation tokens exist only in email; locally they are in `storage/logs/laravel.log`.
* A suspended account gets `403 account_suspended` on every request and its session is ended.

## 9. Frontend Integration Gaps

None of these blocked frontend development. **G1 was resolved in the frontend-handoff pass** (`farms[]` in the auth state); the others were not changed.

| # | Missing capability | Affected frontend workflow | Recommended backend change | Severity |
|---|---|---|---|---|
| G1 | ~~No endpoint lists the farms a user belongs to~~ **Resolved (GAP-07):** `GET /auth/me → data.farms[]` = `{id, name, role}` of every ACTIVE membership; selection stays the `X-Farm-Id` header (no switch endpoint) | Farm switcher / multi-farm users | — | Resolved |
| G2 | `GET /auth/me` does not return `membership.permissions` — **kept by design**: permissions are per selected farm and stay authoritative on `GET /farm` | App startup needs two calls (`/auth/me` then `/farm`) | none (documented two-step startup; with several farms the second call follows the selection) | Low |
| G3 | No `GET /permissions` catalogue (blueprint listed it) | Permission-matrix admin screens | Only needed if a role editor is planned; `GET /roles` already returns the preset lists | Low |
| G4 | Boolean query parameters accept `true`/`false` on master-data lists only; elsewhere only `1`/`0` | Filters/toggles; docs and generated clients disagree | Normalise booleans in a shared FormRequest trait | Low (documented) |
| G5 | Notifications are pull-only (generated hourly, no push/websocket) | Real-time bell | Acceptable for V1; add polling guidance (done) or SSE later | Low |
| G6 | OpenAPI response statuses/types are inaccurate for several action endpoints (D2) | Generated TypeScript clients | Fix Scramble annotations (`@response` / `#[Response]`) and re-export `docs/api/openapi.json` | Low-Medium |
| G7 | Invitation link/token is only emailed; the API never returns it | Owner cannot copy/share an invite link in the UI | By design (token never stored/returned); only change if a share-link feature is required | Low |
| G8 | Exports and notifications depend on a running queue worker and scheduler | Local setup, "export stays queued" | Document in the runbook (done in the integration guide) | Info |
| G9 | No server-side `sort` on farm list endpoints | Sortable tables | Add `sort`/`direction` where needed; clients sort the current page meanwhile | Low |

## 10. OpenAPI and documentation checks

* `php artisan scramble:export` was re-run on the final code and the result committed to `docs/api/openapi.json` (**184 paths, 233 operations**; the earlier "183 paths" figure was stale). The inaccuracies in D1/D2/D7 are unchanged. The regeneration also refreshed two unrelated nullable types of the inventory-movement resource (pre-existing drift, no code change).
* Full Feature suite after the frontend-handoff pass (`php artisan test --testsuite=Feature`, MySQL 8): **872 tests, 11,983 assertions, 0 failures** (the previous 4 clock-boundary failures did not occur: they only appear when the UTC and Lagos days differ). The new `tests/Feature/Api/FrontendHandoffGapsTest.php` has 19 tests / 688 assertions.
* Full suite (informational, nothing in the application was changed): 823 tests, 11,465 assertions, **4 failures**, all clock-boundary tests that compare the UTC day with the Lagos day (`BreedingTest::test_50_eggs_expected_40_actual_37_adds_exactly_37_once`, `CropOperationsTest::test_harvest_lots_and_receiving_location_rules`, `FinanceTest::test_package_conversion_lots_and_expiry_flow_through_the_purchase`, `InventoryTest::test_movement_filters_pagination_and_validation`). They ran at 23:xx UTC / 00:xx Lagos, when "today" differs between the two zones; this is an existing clock sensitivity unrelated to this task.
* `git diff --check`: clean (tracked changes and every new file).


## 11. Keeping the package current

* The collection, saved responses and docs were generated from a scenario that was executed against a disposable `farm_management_test` database by a throwaway harness (a PHPUnit file that drives the Laravel test client). The harness is **not** committed; no application code, route, migration or test was changed.
* When an endpoint changes: edit the affected Postman request (body, Tests capture, description), re-send it (or re-run its flow) against a development server to refresh the saved example, re-export the OpenAPI file (`php artisan scramble:export --path=docs/api/openapi.json`; it needs `memory_limit` of about 1 GB and takes a couple of minutes) and update this report.
* The workflows are the regression check for the package: run **99 — Workflows** in order on a fresh database after any API change; every request carries Tests assertions on its status.

## Addendum — Feed, eggs and milk stock (2026-10-06)

The collection was extended for the one-event / one-entry stock work described in `docs/api/FEED-EGGS-MILK-STOCK.md`. Everything above remains true except the deltas below (historical counts; the current ones are in §1).

| Metric | Before | After |
|---|---|---|
| Laravel API routes (`/api/v1`) | 232 | **233** (`GET /inventory/output-balances`); 233 / 233 covered |
| Reference requests (folders 00-22, 90) | 264 | **273** (+9: output balances, donate eggs IN, give away eggs OUT, donate feed OUT, egg movement history, record milk, start incubation taking eggs, cancel incubation returning eggs, sell surplus feed) |
| Workflows | 14 (Flows 1-13 + S) | **15** (+ Flow 14 — Feed, eggs and milk stock, 11 requests) |
| Workflow requests | 148 | **159** |
| Total requests | 412 | **432** |
| Environment variables (values in the environment file) | 99 | **108** (`egg_item_id`, `milk_item_id`, `movement_egg_in_id`, `movement_egg_out_id`, `breeding_project_egg_id`, `sale_feed_id`, `record_milk_id`, `record_eggs_movement_id`, `dairy_cycle_id`; plus the existing set) |

* Saved responses of the nine new requests (and the refreshed `Inventory option catalogue` and `Record egg collection` examples) were produced by running the real scenario through the Laravel test client as the real authenticated users (not hand-written). The scenario is verified through real HTTP/API integration tests (`tests/Feature/Inventory/OutputStockTest.php`); Flow 14 was **prepared** but not executed at that time — **corrected by the next addendum:** when it was first executed (against the final behaviour) it failed because it depended on a `context_id` created only in folder 06; it is now self-contained and passes. Its steps use the same variables and prerequisites as Flows 3 and 5.
* Behaviour changes that affect existing requests: `Record egg collection (compound quantity)` now also creates the stock-in (it sends `details.inventory.storage_location_id = {{store_id}}` because Flow S creates two stores); The feed examples of `Issue stock (stock-out)` now use reason `spoiled` (generic `use` is refused for feed items: `422`, use a `feed_use` record). `Issue stock (stock-out)` / `Receive stock (stock-in)` gained `output` and the new reason sets; a feed_use movement is now reason `production_use`; sale stock lines accept feed.
* New collection variable `date_today` (farm-local day) was added to the pre-request script for the incubation start date.
* The Flow 10 "sale of eggs" still sells the manual `Table Eggs` item from Flow S; the farm's own `Eggs` item (created by the first egg collection) is a separate produce item, so the existing flow is unaffected.

## Addendum — Frontend-handoff correction pass, GAP-01 … GAP-08 (2026-10-06)

Backend additions (all additive; see `docs/api/API-CONTRACT.md` §13.1): `permissions_required`, `inventory` and `area_fields` on record schemas; `available_record_types[]` on the cycle detail; `kind` / `is_system_managed` on inventory items; `manual_for_kinds` and `flat_lists` on the reason catalogue; `url` beside `path` on every metadata route and `url`/`path` beside `endpoint` on `record-prefill` and `quick_add[]`; `farms[]` in the auth state; `output: eggs|milk` on sale and purchase stock lines. **GAP-09 was not implemented.**

| Metric | Before | After |
|---|---|---|
| Laravel routes | 233 | **233** (no route added or removed) |
| OpenAPI | 184 paths / 233 operations | **184 / 233** (schemas and descriptions changed) |
| Reference requests (00-22, 90) | 273 | **283** (+10) |
| Workflow requests | 159 | **170** (Flow 14: 11 → 22) |
| Total requests | 432 | **453** |
| Environment variables | 108 | **111** (`purchase_egg_id`, `sale_egg_id`, `sale_milk_id`) |

New reference requests (each also a Flow 14 step except the schema request): `Show the egg stock item (kind, is_system_managed)`, `Receive eggs from other sources (stock-in reasons)`, `Receive milk (stock-in, no item setup)`, `Receive feed from other sources (stock-in reasons)`, `Write off or use eggs (stock-out reasons)`, `Write off or use milk (stock-out reasons)`, `Record a purchase of eggs (by output)`, `Record a sale of eggs (by output)`, `Record a sale of milk (by output)`, `Get the egg collection record type schema`. Flow 14 also gained two prerequisite steps (measurement context + crate conversion) so it no longer depends on folder 06.

* **P1 (milk):** `Receive milk` (examples `opening_balance`, `donation`, `purchase`, `production` → 422), `Write off or use milk`, `Record a sale of milk (by output)`.
* **P2 (IN reasons):** eggs `received` / `purchase` / `other` (and `production` → 422), feed `aid` / `production` / `donation` / `received`.
* **P3 (OUT reasons):** eggs `damaged` / `spoiled` / `internal_use` / `lost` (+ `sale` → 422, too many → 409), milk `spoiled` / `internal_use` / `donation` / `lost` (+ `sale` → 422).
* **P4 (output sales):** `Record a sale of eggs (by output)` and `… milk …` (+ `409 insufficient_stock`, `422` several stores); purchase by output (+ `422` both / neither / no store).
* **Descriptions and Tests** of the affected existing requests were extended (auth state, cycle detail, record types, inventory items, option catalogue, prefill, dashboard, sales, purchases) with assertions for the new fields.
* **Saved examples:** every example of the new requests and the refreshed ones (auth-state calls in Flows 1, 2, S, 13; cycle detail/summary; inventory items; record types; option catalogue; prefill; dashboard) was captured from the real response of the live run. Seven Auth-state examples that were not re-executed (reference-folder copies of register, verify email, login ×2 and onboarding; `Accept a farm invitation` in folder 03 and Flow 13) had `farms[]` derived from their own `farm` object (`[]` when `farm` is null) — mechanical, not hand-written content, but not a fresh capture.
* **Execution (Flow 14 and all others):** Newman against `php artisan serve` on a fresh MySQL 8 database (migrate + seed, a platform admin granted by `platform:grant-admin`), flows in the order 1, 2, S, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 13: **170 requests, 361 assertions, 0 failures.** Flow 11 needs a running queue worker (the harness started `queue:work` and spaced the requests 1.5 s) and Flow 12 needs `notifications:generate`; without them Flow 11 `Download an export` answers `409` (not ready) and Flow 12 `Mark a notification read` `404` — both are the documented prerequisites, not regressions. The OTP and invitation token were read from `storage/logs/laravel.log` between requests.
* **Not re-verified:** validation against the official Postman Collection v2.1 JSON Schema (the schema host is not reachable from the build sandbox). The collection loads and runs in Newman, and every new item is a structural clone of an existing valid item.
* The tool used for the capture/verification run is a throwaway script and is **not** committed.

## Addendum — Phase 22 (Marketplace foundation & seller shops)

* 19 new `/api/v1` routes (OpenAPI 184 -> 203 paths; no existing path or schema changed): 14 seller routes, 2 public routes, 7 platform-admin routes (14+2+7 = 23 operations, 19 new path items).
* Postman: new folder **23 — Marketplace (seller shops)** (17 requests incl. the two public ones) and **90 — Platform Admin → Marketplace Shops** (7 requests); +3 environment variables (`shop_id`, `shop_slug`, `shop_member_id`; 114 total). These requests carry descriptions and Tests scripts but **no saved example responses and were not executed with Newman** (the contract is verified by `tests/Feature/Marketplace/MarketplaceShopTest.php` and the OpenAPI test instead). Earlier counts in this report are not updated.
* Contract: `docs/api/PHASE-22-MARKETPLACE.md`.

## Addendum — Phase 22 final review: marketplace-only seller routing (2026-10-07)

The auth state gained `marketplace.shop_count` and the `next_action` value `marketplace` (verified, no active farm, member of at least one shop). Affected Postman requests (descriptions extended; `Get current auth state (me)` asserts `marketplace.shop_count`): register, verify email, me, login, Google, onboarding (reference folders and flow copies). Saved Auth-state examples that were not re-captured received `marketplace: {shop_count: 0}` mechanically (they all belong to users without shops); three **real** examples captured from a live server were added to folder 01 (`me` before the first shop, `me` after creating a shop, login as a marketplace-only seller). Request count unchanged by this correction (collection total 477, of which 170 in the flows). The 14 flows were re-run with Newman after the change: see WORKLOG for the result.

## Addendum — Phase 23 (Marketplace product listings, pricing & images)

* 23 new `/api/v1` path items / **27 new operations** (OpenAPI 203 -> **226 paths, 256 -> 283 operations**; no existing path changed; `AuthStateResource.marketplace.shop_count` gained `minimum: 0` after the redundant `@var` warning was removed): 17 seller operations (2 lookups, listing CRUD, lifecycle, price preview, eligible inventory, photos), 5 public, 5 platform-admin. Laravel `/api/v1` operations: **283** (GET 143, POST 98, PATCH 28, PUT 9, DELETE 5); every one is in the reference folders (parameterised literal paths such as `/public/marketplace/catalogue-images/chicken` count for their route).
* Postman: new folder **24 — Marketplace listings** (26 requests), **90 — Platform Admin → Marketplace Listings** (5 requests) and the executable **Flow 15 — Marketplace listings** (48 requests); +8 environment variables (`seller_email`, `listing_id`, `listing_slug`, `listing_version`, `listing_b_id`, `listing_b_slug`, `listing_image_id`, `catalog_image_id`) = **122**. Reference requests 338, workflow requests 218, total **556**; 516 have a saved example, 690 saved examples, 103 requests with an error example.
* `docs/postman/samples/listing-photo.png` is a small generated picture (no third-party imagery) used by the upload request.
* **Execution:** Flow 15 was run with Newman (`--working-dir docs/postman`) against `php artisan serve` on a fresh MySQL 8 database with a queue worker: **48 requests, 88 assertions, 0 failures**. The saved examples of folders 24/90/Flow 15 come from that run (log-in/out, register and OTP responses are not saved). Two folder-24 requests have no saved example (`Fetch a photo file (member)`, `Public photo file`: binary responses). Flows 1-14 were not re-run in this pass (no change to their requests).
* Not executed against the real application: `Eligible inventory` for a farm-backed shop (the flow's shop is marketplace-only, so it shows the `422`); farm-backed behaviour is covered by `MarketplaceListingInventoryTest`.
* Contract: `docs/api/PHASE-23-MARKETPLACE-LISTINGS.md`; image runbook `docs/api/PHASE-23-IMAGE-ASSET-RUNBOOK.md`.

## Addendum — Phase 24 (Buyer enquiries & controlled negotiation)

* 8 new `/api/v1` path items / **10 new operations** (see `docs/api/openapi.json`; no existing path changed): buyer `POST /marketplace/listings/{slug}/offers|purchase-intent`, `GET …/{slug}/offer-status`, `GET /marketplace/my/enquiries`, `GET /marketplace/my/offers/{offer}`; shop `GET /marketplace/shops/{shop}/offers[/{offer}]`, `POST …/offers/{offer}/accept|reject`, `GET …/shops/{shop}/purchase-intents`.
* Postman: new folder **25 — Marketplace offers & purchase intents** (10 requests), +2 environment variables (`offer_id`, `intent_id`), and the executable **Flow 16 — Marketplace negotiation** (79 requests, +4 environment variables: `neg_seller_email`, `buyer_email`, `staff_email`, `manager_email`; 128 in total). The reference folder 25 carries Tests scripts but no saved examples; behaviour is also covered by the PHPUnit suites listed in `WORKLOG.md`, including multi-process concurrency tests.
* **Execution (Flow 16):** Newman against `php artisan serve` on the isolated test database (`farm_management_test`, fresh `migrate:fresh --seed`, platform admin granted): **79 requests, 197 assertions, 0 failures** (83 requests counted by Newman incl. 4 harness OTP lookups). Covers: buyer offer, seller accept and reject, offer limit, fixed-price refusal, purchase intent, staff/non-member refusals, and a no-leak check of the seller's phone, e-mail and address on every negotiation response. Database check after the run: `marketplace_offers` 4, `marketplace_offer_events` 8, `marketplace_purchase_intents` 2 and **0 rows** in inventory items/lots/movements, sales, sale items, invoices, payments, purchases and finance transactions. Flows 1-15 were not re-run (no change to their requests).
* Three new platform settings are exposed through the existing Platform Admin settings requests (`marketplace_max_offers_per_buyer`, `marketplace_offer_expiry_hours`, `marketplace_min_offer_percent`).
* Contract: `docs/api/PHASE-24-MARKETPLACE-OFFERS.md`.

## Addendum — Phase 25 (Marketplace deal summary & fulfilment)

* 21 new `/api/v1` path items / **21 new operations** (OpenAPI 236 -> 257 paths, 293 -> 314 operations, 0 warnings) (see `docs/api/openapi.json`; no existing path changed): buyer `POST /marketplace/my/offers/{offer}/deal`, `GET /marketplace/my/deal-confirmations/{confirmation}`, `POST …/deal-confirmations/{confirmation}/confirm`, `GET /marketplace/my/deals[/{deal}]`, `POST …/deals/{deal}/complete|cancel|report`, `GET …/deals/{deal}/contact`; shop `POST /marketplace/shops/{shop}/purchase-intents/{intent}/confirm`, `POST …/deal-confirmations/{confirmation}/withdraw`, `GET …/shops/{shop}/deals[/{deal}]`, `POST …/deals/{deal}/complete|cancel|report`, `GET …/deals/{deal}/contact`; platform admin (read-only) `GET /platform-admin/marketplace/deals[/{deal}]`, `…/deals/{deal}/contact`, `…/deal-reports`.
* Postman: new folder **26 — Marketplace deals & contact exchange** (21 reference requests, no saved examples), +2 environment variables used by the reference requests (`deal_id`, `confirmation_id`) and the executable **Flow 17 — Marketplace deals** (92 requests, +8 environment variables: `deal_seller_email`, `deal_buyer_email`, `deal_buyer2_email`, `deal_staff_email`, `deal_manager_email`, `deal_b_id`, `deal_offer_id`, `deal_intent_id`).
* **Execution (Flow 17):** Newman against `php artisan serve` on the isolated test database (`farm_management_test`, fresh `migrate:fresh --seed`, platform admin granted): **92 requests, 226 assertions, 0 failures** (97 requests counted by Newman incl. 5 harness OTP lookups). Covers both deal doors, frozen terms, the null delivery charge, two-sided self-reported completion, cancellation ending contact, a report after cancellation invisible to the other party, contact exchange with the pickup-only address, staff/stranger refusals and the read-only admin review, with a no-leak check on every non-contact response. Database check after the run: 2 deals, 1 confirmation, 1 report, 4 contact-access rows, and **0 rows** in inventory items/movements, sales, invoices, payments and finance transactions. Flows 1-16 were not re-run (no change to their requests).
* One new platform setting is exposed through the existing Platform Admin settings requests (`marketplace_deal_confirmation_hours`).

## Addendum — Phase 26 (marketplace monetisation)

Folder **27 — Marketplace monetisation (seller plans & promotions)** adds 19 reference requests (9 seller, 9 platform-admin, 1 webhook) covering the 19 new routes plus 6 environment variables (`seller_plan_id`, `promotion_package_id`, `payment_reference`, `admin_plan_id`, `admin_package_id`, `promotion_id`). **No saved example responses and no executable workflow for this folder:** the payment steps need a real Paystack test-mode transaction, which was not available; the behaviour is covered by the PHPUnit suite against a faked Paystack. The webhook request is reference-only (it needs a correctly signed body). OpenAPI regenerated (275 paths).
