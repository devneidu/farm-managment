# Postman package coverage report

Package: `docs/postman/Farm-Management-API.postman_collection.json` (Postman Collection v2.1), `docs/postman/Farm-Management-Local.postman_environment.json`, `docs/postman/WORKFLOWS.md`, with the contract in `docs/api/API-CONTRACT.md` and `docs/api/FRONTEND-INTEGRATION.md`.

> **Single source of truth.** `docs/postman/Farm-Management-API.postman_collection.json` is the **only** Postman collection. The root `postman/` folder (the Postman-app YAML Git-sync copy) was removed; every mention of the "YAML tree" / "YAML export" in the older addenda below is historical. Postman changes are made in the JSON collection (and its environment file) only.

## 1. Summary

Figures below were measured on the final collection after the frontend-handoff correction pass (GAP-01 … GAP-08, see the addendum at the end); earlier versions of this report carried approximate counts.

| Metric | Value |
|---|---|
| Laravel API routes (`/api/v1`, `php artisan route:list --path=api/v1`) | **343** (GET 177, POST 121, PUT 10, PATCH 30, DELETE 5; `HEAD` aliases of `GET` not counted) - re-measured in Phase 29 |
| OpenAPI (`docs/api/openapi.json`) | **284 paths, 343 operations** |
| Routes intentionally excluded | 0 of the `/api/v1` routes (see §2 for the 7 non-API framework routes) |
| Postman requests in the reference folders (00-28, 90) | **408** |
| Postman requests inside workflows (99) | **457** (copies of reference requests plus flow-only steps) |
| Total Postman requests | **865** |
| Endpoint coverage (distinct method+route in the reference folders, matched against `route:list`) | **343 / 343 = 100.0 %**; 0 requests point at an unknown route (one flow step, `POST /platform-admin/marketplace/deals/{deal}`, deliberately asserts `405`) |
| Requests with a saved success example | 519 (of 865); folders 27-28 and Flows 15-19 carry executable assertions instead |
| Requests with at least one saved error example | 104 |
| Saved example responses | 697 |
| Workflows | **20** (Flows 1-19 plus Flow S) = 457 requests |
| Environment variables | **151** |
| Undocumented endpoints | **none** |

Saved responses come from executing the scenario against the real application (earlier packages: a throwaway Laravel-test-client harness; this pass: Newman against a live local server and real HTTP, driven by an uncommitted script). The only exceptions are seven Auth-state examples that were not re-executed (the reference-folder copies of register, verify email, login (two examples) and onboarding, plus `Accept a farm invitation` in folder 03 and in Flow 13): their `farms[]` field was derived mechanically from the example's own `farm` object (single-farm users), see the addendum. The workflows were executed in their own order on a fresh database: 170 requests, 361 assertions, 0 failures (addendum).

## 2. Routes and exclusions

All 343 `/api/v1` routes are in the collection (232 at the time this section was written; Phases 22-27 added the rest). The seven routes outside `/api/v1` were deliberately not documented because they are not part of the frontend API:

| Route | Why excluded |
|---|---|
| `GET /` | Laravel welcome page (web) |
| `GET /up` | Laravel framework health check (the API health endpoint is `GET /api/v1/health`) |
| `GET /docs/api`, `GET /docs/api.json`, `GET /_scramble/dev-tools/devtools.js` | Scramble API documentation UI, available only with `APP_ENV=local` |
| `GET\|PUT /storage/{path}` | Laravel's local-disk file-serving/upload helper (`storage.local`): signed-URL infrastructure, not an application endpoint |

Phase 20 (WhatsApp / AI parsing) and Phase 28 (Community) are deferred and have no routes. The word *WhatsApp* appears in the package only in (a) the Platform Admin setting key `support_whatsapp` (a real V1 setting holding a support phone number) and (b) explicit statements that WhatsApp is not part of V1.

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
| 09 — Operational Records | 26 | 12 | 26 | 9 |
| 10 — Inventory | 32 | 19 | 32 | 12 |
| 11 — Health | 10 | 9 | 10 | 2 |
| 12 — Breeding | 13 | 9 | 11 | 2 |
| 13 — Tasks, Templates & Calendar | 34 | 25 | 26 | 8 |
| 14 — Crop Operations | 12 | 2 | 12 | 2 |
| 15 — Contacts & Purchasing | 10 | 8 | 10 | 4 |
| 16 — Finance | 10 | 8 | 10 | 3 |
| 17 — Sales, Invoices & Payments | 23 | 19 | 23 | 12 |
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

Folder **27 — Marketplace monetisation (seller plans & promotions)** adds 19 reference requests (9 seller, 9 platform-admin, 1 webhook) covering the 19 new routes plus 6 environment variables (`seller_plan_id`, `promotion_package_id`, `payment_reference`, `admin_plan_id`, `admin_package_id`, `promotion_id`). **No saved example responses.** Superseded in Phase 29: Flow 19 now executes the non-payment steps (catalogue, flags, seller reads, checkout refusal without a key, unsigned webhook 401). The payment steps need a real Paystack test-mode transaction and are listed under "Not executable" below; the behaviour is covered by the PHPUnit suite against a faked Paystack. OpenAPI regenerated (275 paths).

## Phase 27 — Safety administration

Folder 28 adds 16 reference requests for shop/listing intake, owned reports, admin summary/offers/reports, triage outcomes, explicit linked enforcement, and reason-required reinstatement/lifting. `report_id` and `report_type` are environment variables; intake saves them. Use a verified participant for intake/my reports, support/admin for platform reads and admin for handling. Review before resolving/dismissing. Resolution, dismissal and enforcement examples are alternatives, not a sequential runner. Deal reports keep existing participant endpoints; select `report_type=deal` for their admin workflow. All statuses/counts and errors: `docs/api/PHASE-27-MARKETPLACE-SAFETY.md`. No saved HTTP responses for this folder; Phase 29 added Flow 18 (executed with Newman, see the Phase 29 addendum) and feature tests validate the workflows. Prior reinstate/lift reference requests were updated to supply the newly required reason.

## Addendum — Phase 29 (Postman finalization and backend handover)

**Route audit.** `php artisan route:list --path=api/v1 --json` (343 method+path routes) was matched against every request URL in the collection after normalising `{{base_url}}`, `{{variables}}` and literal sample ids to path parameters: **343 / 343 routes have a request in the reference folders; 0 requests point at an unknown route** (one flow step, `POST /platform-admin/marketplace/deals/{deal}`, deliberately asserts `405`). 187 of the 343 routes are also exercised by an executable flow. Every request's method and URL matched a route; the bodies of folders 27-28 were checked against the Phase 26/27 contracts and are now executed by Flows 18-19. The only contract-level corrections were the header and sample-body fixes below.

**Changes in this pass (collection and environment only; no backend code changed).**
- New executable **Flow 18 - Marketplace reports & moderation** (38 requests) and **Flow 19 - Marketplace seller plans & promotions** (30 requests, Paystack not exercised). Both build on Flow 17's seller shop.
- Folder 27: the promotion-package sample body now includes `"is_active": true` (packages are created inactive; without it the next promotion checkout answers `409 package_not_available`); both checkout requests accept `502/503` when Paystack is not configured or reachable; the webhook request carries the `x-paystack-signature` header (`{{paystack_signature}}`) instead of the frontend CSRF/Origin headers it does not use.
- Environment: +5 variables (`paystack_signature`, `report_a_id` ... `report_d_id`), 151 in total.
- `docs/operations/BACKEND-HANDOVER.md` added; `LAUNCH.md` no longer claims there is no payment integration.

**Newman execution (disposable MySQL 8 database `farm_newman`, fresh `migrate --seed` of every migration including the Phase 26/27 ones, platform admin granted, `php artisan serve`, a queue worker, `MAIL_MAILER=log`, no Paystack key).**

| Run | Requests | Assertions | Failures |
|---|---:|---:|---:|
| **All 20 flows in one process** (order 1, 2, S, 3-12, 14, 13, 15-19) | **457** | **1042** | **0** |
| (breakdown) Flow 17 / Flow 18 / Flow 19 | 92 / 38 / 30 | 226 / 101 / 64 | 0 / 0 / 0 |
| Reference folders 00-28 and 90 run once, in order, on a fresh database | 405 | 682 | 171 state-dependent assertion failures; 0 transport failures |

The reference folders are one request per route, not a sequential runner: they depend on plan limits, prior state and out-of-band inputs that the flows set up (for example a farm plan upgrade, an invitation token from the email, a published listing), so a straight run produces cascading 404/409/422 results. Those failures were **not triaged individually and are not claimed as passes**; executable correctness is established by the flows. Harness notes: the OTP and the invitation token are read from `storage/logs/laravel.log`, `php artisan notifications:generate` runs before Flow 12, the cache is cleared between flows (registration is limited to 10/hour/IP), and Flow 11's export needs a few seconds for the queue worker.

**Requests that cannot be executed here.**
- Paystack-dependent (need `PAYSTACK_SECRET_KEY` test-mode credentials and a real card payment): `POST /marketplace/shops/{shop}/payments/{reference}/verify` on a real payment, a correctly signed `POST /public/marketplace/payments/paystack/webhook`, `POST /platform-admin/marketplace/promotions/{promotion}/cancel` (needs a paid promotion), and the happy-path `200/201` of both checkouts.
- Not covered by any executable flow (reference-only; examples were captured in earlier phases, not re-run now): 156 routes, mostly single-resource reads/updates and admin screens (for example `PATCH /account`, `PUT /account/password`, password-reset requests, `POST /auth/google`, `/platform-admin/plans*`, `/platform-admin/users/*`, `/platform-admin/work-templates*`, `/subscription/cancel|resume`, shop `close|reopen|request-verification`, `/platform-admin/marketplace/shops/{shop}/reject|suspend|verification`, `POST /records/{record}/attachments`).
- Google sign-in needs a real Google ID token.

**Deferred features.** The collection and environment contain no Phase 20 (WhatsApp/AI) or Phase 28 (Community) request. The only "WhatsApp" strings are the `support_whatsapp` platform setting, a marketplace contact channel preference, and statements that WhatsApp is not in V1.

## Livestock batch creation addendum — 2026-10-09

One GET route added: `/master/species/{species}/batch-reference` (344 total application operations; no further route). Postman changes for the livestock batch-creation contract (`docs/api/LIVESTOCK-BATCH-CREATION.md`):

* **Folder 05:** `Selected animal batch reference` (Chicken, now with the documented `include_inactive` query, real 200/404/422 examples), new `… (Cattle)` and `… (Fish)`, new `List species (all animals)`; `List farm operations` also captures `op_cattle_id`/`op_fishery_id`; stale "no system breeds" note and empty example of `List breeds of a species` replaced with the real list.
* **Folder 08:** `Start a livestock batch` (Chicken) documents every field and has fresh 201/409/422 examples; new `… (Cattle — dairy, with price and supplier)`, `… (Fish — table fish)` and four negative requests (missing `production_purpose`; purpose, growth stage and breed from another species). `Edit an active cycle` notes the `baseline_locked` rule for the four new fields. Saved read examples (list, show, summary) and Flow 3 steps 4/5/9/11 were re-captured and show the new `livestock` fields.
* **Environment:** +9 empty capture variables (`op_cattle_id`, `op_fishery_id`, `species_cattle_id`, `species_fish_id`, `breed_cattle_id`, `breed_fish_id`, `breed_chicken_system_id`, `cycle_cattle_id`, `cycle_fish_id`). No new auth, farm id or token; existing `supplier_id`, `area_id`, `breed_id` are reused (the Cattle request creates a supplier contact only if `supplier_id` is still empty).

**Postman follow-up (2026-10-09, after `091a28d`).** Root causes and fixes of the three requests that failed in the first Newman run:

* `Create a custom breed` (folder 05) returned `409 duplicate_name` because Flow S had already created `Isa Brown (Ibadan strain)` in the same database (case/space-insensitive duplicate, a correct backend answer). The request now names the breed `{{custom_breed_name}}` (`Isa Brown (Ibadan strain) <timestamp>`, set by a pre-request script) and the rename request derives its name from it, so the folder is repeatable. New request `Create a custom breed — duplicate name (409)` posts the same name again and asserts `409 duplicate_name` with `details.existing_id == breed_id`, so the conflict stays covered. Flow S step 8 keeps the stable name later flows rely on; its test accepts `201` or the documented `409` (active farm breed) and reuses `details.existing_id`, so Flow S can be rerun.
* `Close`/`Reopen a production cycle` used `{{cycle_pilot_id}}`, which nothing ever captured (the docs pointed at a "second batch" request that does not exist), so the URL had an empty id. `Close` now starts its own disposable Chicken batch (`Pilot flock <timestamp>`) in a pre-request script and captures `cycle_pilot_id`; `Reopen` reuses it. `{{cycle_id}}` (Flow 3 and folders 09-17) is never closed. The tests assert the pilot batch (not `cycle_id`) becomes `closed` and then `active` again with no end date.
* Saved examples re-captured from real responses: `Edit an active cycle`, `Close` (200 and the 409 already-closed), `Reopen`, Flow 7 step 6, Flow 10 step 4, the Chicken create `403` (a real `farm_worker` response with the current body), and the custom-breed 201/409/rename examples.
* Environment: +1 variable `custom_breed_name`. Descriptions of Close/Reopen/Create custom breed/Flow S step 8 and the collection variable table updated.

**Newman (2026-10-09, live `php artisan serve`, throwaway MySQL 8 database `farm_management_newman`, queue worker running; Flow 1, a platform admin and Flow S run first, 22 requests / 44 assertions / 0 failures).** Folders 05 + 08 in collection order: 37 requests, 84 assertions, 0 failures (38 requests while capturing, with a temporary Close-again probe for the 409 example). Dependent workflows Flows 3-12 and 14, then Flow 13 (including a temporary probe for the 403 example), on a fresh database: 136 requests, 284 assertions, 0 failures. Probe requests exist only in the temporary run collection.

**Remaining gaps.** Platform-admin reference-value maintenance (`/platform-admin/master/reference-values`, `breed_field_label` on species) is documented in the API doc but has no livestock-specific Postman example; Honeybee/Snail examples are not included. Reference folders use stable names for some records (the Chicken batch `October Broilers`, the crop project and the custom crop variety), so running folders 05/08 a second time against the same database returns the backend's `409` duplicate-name answers there; run them on a fresh database or Flow S/Flow 3 state, as documented. The backend's misleading "at most two decimal places" message for a negative `acquisition_price_per_animal` is unchanged (backend not in scope). Flow 11 (export) and Flow 12 (notifications) depend on a running queue worker polling quickly.

## Addendum - Transactions page backend (Finance)

* Folder **16 — Finance** gains two requests (`Record an expense by quantity and unit price`, `Record income (services, paid by mobile money)`) and five saved examples (amount/quantity 422s, `possible_duplicate` 409 and its confirmed 201, product-sales category 422). The categories request now also captures `cat_egg_sales_id` and `cat_services_id`.
* Every saved finance-transaction example in the package (11) was refreshed to the new row shape (`payment_method`, `quantity`, `unit_price`, `source` object, `can_reverse_directly`); category examples carry `manual_entry`.
* New totals: reference folders 406 → **408**, all requests 863 → **865**, with a success example 517 → **519**, with an error example 103 → **104**, saved examples 690 → **697**. No route was added (343/343 unchanged), so endpoint coverage is unchanged. The new examples are real responses captured from the implemented code; Newman was not re-run.
* Frontend contract: `docs/api/FRONTEND-TRANSACTIONS-FLOW.md`.

## Addendum - quick sell flow

* New route `GET /sales/sellables` (now 344 `/api/v1` routes; `docs/api/openapi.json` was intentionally not regenerated in this change and still lists 343 operations).
* Folder **17 — Sales, Invoices & Payments** gains five requests: `What can I sell? (sellables)`, `Sell: paid in full, quick buyer, quantity x price`, `Sell: part payment (animals + stock)`, `Sell: not paid yet (no payment block)` and `Cancel a sale and reverse its payments (one step)`, with 10 saved examples captured from the running code (200/201 successes, an idempotent retry, `422` payment above total / amount mismatch / buyer conflict, `409 insufficient_stock`, `409 sale_has_payments`, worker `403`). The old example "Farm worker has no sale access (403)" is now "Vet has no sale access (403)" because Farm Worker holds `sale.view` and `sale.create`.
* Frontend contract: `docs/api/FRONTEND-SALES-FLOW.md`. The per-folder counts in the table above (row 17) were updated by hand; the other totals in §1 were not recomputed.

## Addendum - livestock exits (animals leaving a batch)

* New routes `GET /livestock-exits`, `POST /livestock-exits`, `POST /livestock-exits/{record}/reverse` (`docs/api/openapi.json` was intentionally not regenerated in this change).
* Folder **09 — Operational Records** gains eight requests: slaughtered, culled, sold (quick sell), died, given away, moved to another batch, the list, and undo, with 20 saved examples captured from real responses (including the 422/409/403 cases, the idempotent retry, and the refused undo of a sold row and of a move through `POST /records/{id}/reverse`). The row for folder 09 in the table above was updated by hand; the other totals were not recomputed. The new requests use the existing `{{cycle_id}}` variable and a new, uncaptured `{{record_id}}` (set it from a list row).
* Frontend contract: `docs/api/FRONTEND-HARVEST-FLOW.md`.

## Addendum - breeding fixes (offspring destination, optional sire, limits, workflow on batches)

* No new route (the 9 breeding routes are unchanged; `docs/api/openapi.json` was intentionally not regenerated in this change). New migration `2026_10_31_100000_add_breeding_title_and_outcome_destination` adds `breeding_projects.title` and `breeding_outcomes.destination`.
* Folder **12 — Breeding** gains two requests (`Start a pregnancy project (optional sire, title)`, `Record the outcome into the mother batch (pregnancy default)`), now 13 requests; `Start a breeding project (incubation)` gets `title`, `Record the actual outcome` sends `destination: new_batch` with a new 201 example and a 422 example, and `Reverse a breeding outcome` gains the new-batch undo (201) and insufficient-population (409) examples. 10 new saved examples, captured from real responses. Environment: +3 variables (`breeding_project_preg_id`, `outcome_preg_id`, `bull_cycle_id`). The per-folder row in the table above was updated by hand for the request count only; the other totals in §1 were not recomputed.
* Behaviour changes that affect existing requests: an incubation outcome without `destination` now starts a new batch (default `new_batch`; pregnancy stays `mother_batch`); a `dam` in `parents` must be the project's own batch; `females_bred` and parent `head_count` are capped by the batch's current head count; production-cycle responses carry `livestock.breeding_workflow`.
* Frontend contract: `docs/api/FRONTEND-BREEDING-FLOW.md`.

## Addendum - new batch name/purpose and plan-limit message

* No new route. `Record the actual outcome` (folder 12) documents `new_batch: {title, purpose}` and gains three saved examples captured from real responses: 201 with a chosen name and purpose, 422 on `new_batch.purpose`, and 409 `plan_limit_reached` whose message points to `destination: mother_batch`. Folder 12 stays at 13 requests. `docs/api/openapi.json` was not regenerated.
* Frontend contract: `docs/api/FRONTEND-BREEDING-FLOW.md` §4.2 and §4.3.

## Addendum - tasks & calendar (single form, repeats, edit series, reminders, coming repeats, phone calendar link)

* New routes: `POST /tasks/{task}/edit-series`, `GET|POST|DELETE /calendar-feed`, public `GET /calendar-feed/{token}.ics`. `POST /tasks` gains an optional `repeat` block and `reminders`; `GET /calendar` gains `upcoming_repeat` items (`include_upcoming_repeats=0` hides them); `GET /master/task-categories` gains `description` and `color_group`; the schedule `recurrence` enum gains `monthly` and schedules gain `ends_type` (`batch_end`). `docs/api/openapi.json` was intentionally not regenerated in this change.
* Folder **13 — Tasks, Templates & Calendar** gains six requests (`Repeating task`, `Edit series`, `Calendar link`, `Calendar link on`, `Calendar link off`, `Calendar feed ics`; short names keep the Windows paths short), now 26 requests; the row in the table above was updated by hand (the other totals in §1 were not recomputed). The saved examples are real responses captured from the running application (feed tokens shortened). The two stale descriptions saying reminders are "stored only" were corrected.
* The six requests were added to **both** the canonical collection JSON and the Postman-app YAML export (`postman/collections/Farm Management API — V1/13 — Tasks, Templates & Calendar/`: six `*.request.yaml` files plus ten saved examples under `.resources/`, same real responses; the YAML was generated from the JSON items and parses cleanly). They were **not** executed through Newman or imported into the Postman app in this change; the behaviour is covered by the feature tests `tests/Feature/Work/TasksCalendarTest.php`, `tests/Feature/Work/CalendarFeedTest.php` and `tests/Feature/Notifications/TaskReminderPresetsTest.php`.
* Frontend task link: the reminder e-mail and the phone calendar events open `{FRONTEND_URL}/app/tasks/{task id}`. The path is the single config value `identity.invitations.task_path` (env `FRONTEND_TASK_PATH`, default `app/tasks`).
* Frontend contract: `docs/api/FRONTEND-TASKS-FLOW.md`; reference: `docs/api/PHASE-12-WORK.md`.

## Addendum - routines (any-batch and general routines, save as routine, preview, re-apply, remove)

* New routes (4): `POST /work-templates/{template}/preview`, `POST /tasks/{task}/save-as-routine`, `GET /template-applications`, `POST /template-applications/{application}/remove`. `php artisan route:list --path=api/v1` now measures **362** method+path routes (the 343 in §1 is the earlier figure and was not recomputed). Changed contracts (all new fields optional): `POST /work-templates` accepts `applies_to: farm` (general, no batch), the new anchor `start_date`, and per step the task form's `repeat` block and `reminders`; `GET /work-templates` rows gain `step_count`, `scope`, `scope_label` (+ `suits_batch` with `?production_cycle_id=`); `POST /work-templates/{id}/apply` gains `start_date` and `confirm_reapply` and no longer refuses a second application outright (`409 template_already_applied` now carries `details` and is lifted by `confirm_reapply: true`). Migration `2026_11_06_100000_routines` (step columns `ends_type`, `ends_on`, `reminder_presets`; application columns `start_date`, `status`, `removed_at`, `removed_by`, `tasks_cancelled`; nullable `production_cycle_id`; the one-per-target unique index replaced by a plain index).
* Folder **13 — Tasks, Templates & Calendar** gains eight requests (`New routine`, `New general routine`, `Save as routine`, `Preview routine`, `Apply routine`, `Apply routine again`, `Applied routines`, `Remove routine`), now 34 requests (row above updated by hand; the other totals in §1 were not recomputed). New environment variable `template_application_id` (JSON and both YAML environments). The descriptions of the existing template requests mention the new fields.
* The saved examples are real responses captured from the running application by a throwaway feature test (not committed), so the ids in them are real and consistent with each other. The requests are in **both** the canonical collection JSON and the Postman-app YAML export (eight `*.request.yaml` files and their examples under `.resources/`, parsed cleanly). They were **not** executed through Newman or imported into the Postman app; behaviour is covered by `tests/Feature/Work/RoutinesTest.php`. `docs/api/openapi.json` was intentionally not regenerated.
* Frontend contract: `docs/api/FRONTEND-ROUTINES-FLOW.md`; reference: `docs/api/PHASE-12-WORK.md`.

## Addendum - main store and adding stock in one step

* 1 new operation (`POST /storage-locations/{place}/make-main`) and 3 new Postman requests: `Add stock, new item` and `Issue stock, store not chosen` (10 - Inventory), `Make main store` (07 - Locations), in the collection JSON and as Postman-app YAML (request + examples under `.resources/`). `POST /inventory/stock-in` gained `new_item` and `item_reused`; `storage_location_id` became optional on stock-in/out, adjustments, purchase and sale stock lines, health medicine lines and record `details.inventory`.
* The saved examples are real responses captured from the application by a throwaway feature test (deleted). The requests were not executed through Newman or imported into the Postman app; behaviour is covered by `tests/Feature/Inventory/MainStoreTest.php`. Existing requests that sent a store still work unchanged. `docs/api/openapi.json` was intentionally not regenerated.
* Frontend contract: `docs/api/FRONTEND-WORKFLOWS.md` ("Stores and adding stock"); reference: `docs/api/PHASE-09-INVENTORY.md`.

## Addendum - "Take everything used from my store" setting

* No new operations. `PATCH /farm` gained `settings.use_stock_for_usage`; `GET /farm`, `PATCH /farm` and `GET /auth/me` return `settings` (and `can_add_stock`); `POST /records` (feed_use, fertilizer_application, pesticide_application, planting) gained `details.item_name` and the setting's ON rule (`details.inventory` required); `POST /health-records` medicine lines gained the typed-medicine shape (`medicine_name`, no stock); the record-type, operation and health-type schemas report the setting per farm. Migration `2026_11_08_100000` (farms.settings JSON; nullable item/store/quantity/measurement on `health_record_medicines`).
* 3 new Postman requests: `Use stock setting` (03 - Farms, Team & Access), `Feed use, typed name` (09 - Operational Records), `Vaccination, typed medicine` (11 - Health), in the collection JSON and as Postman-app YAML (request + one example each under `.resources/`). The examples were derived from the neighbouring requests' saved responses and edited by hand to the new shapes; they are **not** captured responses. The requests were not executed through Newman or imported into the Postman app; behaviour is covered by `tests/Feature/Team/FarmSettingsTest.php`, `tests/Feature/Records/UseStockSettingTest.php` and `tests/Feature/Health/TypedMedicineTest.php`. `docs/api/openapi.json` was intentionally not regenerated.
* Permissions follow-up: `inventory.add` (Owner, Manager, Farm Worker) now guards `POST /inventory/stock-in` (incl. `new_item`); `can_add_stock` mirrors it. Stock-in request descriptions in the collection and YAML were updated (no operation or example added/removed).
* Frontend contract: `docs/api/FRONTEND-WORKFLOWS.md` ("Use stock setting"); reference: `docs/api/PHASE-08-RECORDS.md`, `docs/api/PHASE-10-HEALTH.md`, `docs/api/README.md`.

## Addendum - farm-made record types

* 12 new routes. Farm (7): `GET|POST /custom-record-types`, `GET|PATCH /custom-record-types/{type}`, `POST /custom-record-types/{type}/archive|unarchive|copy`. Platform admin (5): `GET /platform-admin/custom-record-types`, `GET .../{type}`, `POST .../{type}/make-public|archive|unarchive`. Changed contracts (all additive): `POST /records` accepts `type: "custom"` + `custom_record_type_id`; `GET /records` accepts `type=custom` and `custom_record_type_id`; every record resource gains `type_name`, `custom_record_type_id`, `custom_schema_version` (+ `custom` on custom records); `GET /master/record-types`, `GET /master/record-operations[/custom:{id}]`, the cycle's `available_record_types` and the dashboard `quick_record` list recordable custom types; new report `custom_records` (filter `custom_record_type_id`) in `GET /reports`, runnable and exportable like any report. Migration `2026_11_09_110000_create_custom_record_types` (table `custom_record_types`; nullable `operational_records.custom_record_type_id` and `custom_schema_version`).
* Folder **09 - Operational Records** gains six requests (`Custom types`, `Make custom type`, `Edit custom type`, `Archive custom type`, `Copy public type`, `Record custom type`), **19 - Reports & Exports** one (`Farm-made records report`), **90 - Platform Admin / Master Data** three (`Custom types admin`, `Make type public`, `Archive public type`), in the collection JSON and as Postman-app YAML (request + examples under `.resources/`, parsed cleanly). New environment variables `custom_record_type_id`, `public_record_type_id` (JSON and both YAML environments). The row counts in the table in section 1 were not recomputed.
* The saved examples are real responses captured from the running application by a throwaway feature test (deleted), so the ids in them are consistent with each other; the request bodies of the refused-edit examples show the real bodies that were sent. The requests were not executed through Newman or imported into the Postman app; behaviour is covered by `tests/Feature/Records/CustomRecordTypesTest.php`, `tests/Feature/Reports/CustomRecordsReportTest.php` and `tests/Feature/Platform/PlatformCustomRecordTypesTest.php`. `docs/api/openapi.json` was intentionally not regenerated.
* Permissions: no new permission. Farm-side management reuses `master_data.manage` (read: `master_data.view`); recording needs `record.create`; platform writes need the `admin` platform role.
* Frontend contract: `docs/api/FRONTEND-CUSTOM-RECORDS-FLOW.md`; reference: `docs/api/PHASE-08-RECORDS.md`, `docs/api/PHASE-18-PLATFORM-ADMIN.md`, `docs/api/PHASE-17-REPORTS.md`.
## Addendum - Income page (sales into money in)

* New route `GET /finance/income` (345 `/api/v1` routes; `docs/api/openapi.json` intentionally not regenerated).
* Folder **16 — Finance** gains `Income list` (2 saved examples: owner with totals, Farm Worker sale rows only). Folder **17 — Sales, Invoices & Payments** gains `Sell: typed product` (201 and the 422 "sells from the store"). Both added to the JSON collection and the Postman YAML tree. Examples are real responses captured from the code; Newman was not re-run.
* Sale responses gain `total`, `amount_received`, `balance`, `money_status`; sale lines gain `product_type`, `entered_quantity`. Totals in §1 were not recomputed.
* Frontend contract: `docs/api/FRONTEND-INCOME-FLOW.md`.

## Addendum — Money forms (form options, purchases with new items)

* Folder **16 — Finance** gains `Form options (income or expense form)` (`GET /finance/form-options?direction=`; 4 saved examples: income choices, expense choices, Farm Worker with no expense options, 422 direction required). Folder **15 — Contacts & Purchasing** gains `Record a purchase with a new item` (`new_item` on a stock line; 201 created, 201 reused, 422 missing unit). Both added to the JSON collection and the Postman YAML tree. Examples are real responses captured from the code; Newman was not re-run.
* `POST /purchases`: stock lines accept `new_item {name, category, unit}` (exactly one of `inventory_item_id` / `new_item` / `output`); the line response gains `item_reused` (only for `new_item` lines). The existing `Record a purchase (stock + expense)` request is unchanged.
* Frontend contract: `docs/api/FRONTEND-INCOME-FLOW.md` (section 1b) and `docs/api/FRONTEND-TRANSACTIONS-FLOW.md` (dynamic Expense form).
* The endpoint total (`openapi.json` / the §1 counts) was not regenerated.

## Addendum — Postman refresh, verified with Newman (chore/postman-refresh)

**Newman run.** Newman 6.2.3 against `php artisan serve` on a fresh local MySQL database (`migrate:fresh --seed`, `MAIL_MAILER=log`, a platform admin granted with `platform:grant-admin`, OTP read from `storage/logs/laravel.log` by a throwaway harness that is not committed). Order: Flow 1, Flow S steps 1-4 (admin login, plans, plan change, owner login), then the reference folders **03 (farm + name only), 05, 06, 07, 08, 10, 09, 11, 15, 16, 17**, the custom report in **19**, and the custom-type admin requests in **90 — Master Data** (as platform admin, interleaved where the copy flow needs them). Final result: **221 requests, 455 assertions, 0 failures** (as counted by Newman, including the OTP lookup helper and requests sent from pre-request scripts). Per section: 05 20, 06 11, 07 15, 08 15, 10 36, 09 35, 11 14, 15 11, 16 12, 17 25, 19 (custom report) 1, 90 (custom types) 3 — all passed. Folders 12-14, 18, 20-28 and the other flows were not run in this pass.

**Collection mistakes fixed (no app code changed; JSON and YAML):**
* Three requests had double-escaped quotes in their Tests script (syntax error): `Make main store`, `Issue stock, store not chosen`, `Add stock, new item`; the last two also used idempotency variables nothing set (now `{{$guid}}`).
* Hard-coded ids from an old capture replaced by variables: the six `Livestock exit: ...` requests (and `Undo a livestock exit`, which now gets `record_id` from `Livestock exit: died`), `Create a medicine` (store), `Sell: part payment` (batch, item, store). Fixed idempotency keys became `{{$guid}}`.
* Hard-coded past dates (2026-10-14) made sales fail against the dated stock ledger: `Sell: paid in full`, `Sell: part payment`, `Sell: not paid yet`, the payment `received_on` and `Cancel a sale and reverse its payments` now use `{{recorded_at_2h}}`, `{{today}}` and a fresh `cancel_at`.
* Missing dependencies: `Create a second storage location` added to 07 (the transfer needs `store2_id`); `Receive stock (produce)` added to 10 (the produce sales had no stock); `dairy_cycle_id`, `health_med2_id`, `purchase_c_id`, `sale_c_id` and `sale_quick_id` are now captured by a request in the same folder; `Reverse a payment` uses `payment_id`; `Cancel a sale (one step)` cancels the quick sale, not the sale used by the later invoice requests.
* Order: `Create a package conversion (inventory item)` moved from 06 to 10 (after the feed item exists); `Make main store` after a store exists; `Egg and milk available balances` after eggs exist; `Reconcile a physical count` right after the stock-out its expected balance depends on; `Record custom type` before `Edit custom type` and `Archive custom type`; `Issue the invoice for a sale` before the payment that would otherwise auto-issue it. `Copy public type` now states that it needs `Make type public` (90 — Master Data) first.
* Wrong assertions: `List farm operations` and `Get a farm operation` asserted the record-type shape (`permissions_required` on the row); they now check the operations shape (code/label/display_mode; `permissions_required.always` on each action). `Sell: typed product` expected 200, the API answers 201. Vaccination bodies asked for 490 animals on a 500-head batch after exits (now 400); `Record an expense` no longer collides with the duplicate-purchase warning (14750.00). The evidence upload now sends `samples/listing-photo.png` (run Newman with `--working-dir docs/postman`).

**Examples re-captured from the real application** (Newman responses, plus a throwaway feature test for the worker view, the overpayment and the accepted invitation; neither is committed): `Use stock setting`, `Feed use, typed name`, `Vaccination, typed medicine`, the sale-payment examples (201 part payment on an uninvoiced sale, 409 `payment_exceeds_balance`), `Get current farm` (owner and the worker example), `Update farm name`, `Get current auth state (me)`, login, onboarding and `Accept a farm invitation`, with the Flow 1, 2, S and 13 copies. They now show `inventory.add`, `settings.use_stock_for_usage` and `can_add_stock`. Not re-captured: `List roles` (its permission lists still omit `inventory.add`), the platform-admin farm list/show (a different resource), and the 403/422 examples of `Update farm name` (their shape did not change).

**Known gaps (unchanged):** the YAML tree has no files for several JSON requests added on earlier branches (the `Livestock exit: ...` requests, `Sell: part payment`, `Sell: paid in full`, `Sell: not paid yet`, `Record a payment on a sale (invoice auto-created)`), so those fixes exist in the JSON only. `docs/api/openapi.json` was not regenerated. No application bug was found by this run.

## Addendum — single JSON collection

* The root `postman/` folder (about 1,760 files: the YAML export of the collection and two environments) was deleted. `docs/postman/Farm-Management-API.postman_collection.json` and `docs/postman/Farm-Management-Local.postman_environment.json` are the only Postman artefacts; from now on Postman updates go to the JSON only.
* `.postman/resources.yaml` (Postman workspace link) no longer maps a cloud collection to the removed folder; it still points at the JSON collection, the JSON environment and `docs/api/openapi.json`.
* Searched docs, README, WORKLOG, scripts, CI (`.github`), `composer.json` and `package.json` for `postman/collections`: the only live reference was `.postman/resources.yaml`.
