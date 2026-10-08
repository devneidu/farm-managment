# Frontend workflows — building every V1 screen without asking anyone

Detailed orchestration for each V1 workflow. **Entry point and rules:** [`FRONTEND-INTEGRATION.md` §24](FRONTEND-INTEGRATION.md#24-building-v1-product-workflows) (metadata ladder, orientation map, GAP index). Field-level payloads: the Postman collection (`docs/postman/Farm-Management-API.postman_collection.json`); contract: [`API-CONTRACT.md`](API-CONTRACT.md); Feed/Eggs/Milk detail: [`FEED-EGGS-MILK-STOCK.md`](FEED-EGGS-MILK-STOCK.md).

Every statement here was checked against the code (routes, FormRequests, services, resources) on 2026-10-06. The frontend-handoff correction pass (GAP-01 … GAP-08) moved the former client-side workarounds into the API: record-type availability, permissions, item kind, stock metadata, route forms, farm discovery and output-named sale/purchase lines are now returned by the backend. The only open item is **GAP-09** (dashboard milk/available-stock KPIs); index in §24.7 of the integration guide.

All paths are relative to `/api/v1`. Never send `farm_id`. Never send a permission string. `recorded_at` format, money strings, idempotency keys, error handling and optimistic-update rules are in the integration guide §9–§17 and are not repeated.

## Contents

0. [Shared building blocks](#0-shared-building-blocks)
1. [Bootstrap, auth, onboarding](#1-application-bootstrap-auth-onboarding) · 2. [Farm setup and operations](#2-farm-setup-and-farm-operations) · 3. [Locations / production areas](#3-locations-and-production-areas)
4. [Create livestock batch](#4-create-livestock-batch) · 5. [Create crop project](#5-create-crop-planting-project)
6. [Record Activity discovery](#6-record-activity-discovery) · 7. [Dynamic record forms](#7-dynamic-record-forms)
8. Livestock records: [mortality](#8-mortality) · [population adjustment](#9-population-adjustment) · [weight](#10-weight) · [temperature](#11-temperature) · [water](#12-water)
9. Feed: [IN](#13-feed-in) · [OUT](#14-feed-out) · [used for livestock](#15-feed-used-for-livestock)
10. Eggs: [IN](#16-eggs-in) · [OUT](#17-eggs-out) · [collection](#18-egg-collection--produced-on-farm) · [donation/purchase/received](#19-egg-donation--purchase--received) · [damage/spoilage/internal use](#20-egg-damage--spoilage--internal-use) · [sale](#21-egg-sale) · [incubation](#22-egg-incubation)
11. Milk: [IN](#23-milk-in) · [OUT](#24-milk-out) · [produced on farm](#25-milk-produced-on-farm) · [purchase/donation](#26-milk-purchase--donation) · [sale/spoilage/internal use](#27-milk-sale--spoilage--internal-use)
12. Crops: [planting inputs](#28-crop-planting-input-consumption) · [fertilizer/agrochemical](#29-fertilizeragrochemical-use) · [harvest](#30-crop-harvest--output)
13. Inventory: [general IN/OUT](#31-general-inventory-stock-in--out) · [transfers](#32-transfers) · [adjustments](#33-adjustments)
14. Health: [records](#34-medicine--health-records) · [lots/withdrawal](#35-medicine-lots-and-withdrawal-behaviour)
15. Breeding: [projects](#36-breeding-projects) · [incubation](#37-incubation-breeding-workflow) · [outcomes](#38-breeding-outcomes)
16. Work: [tasks](#39-tasks) · [calendar](#40-calendar) · [completion → records](#41-task-completion-that-creates-or-links-operational-records)
17. Money: [purchases](#42-purchases) · [expenses/income](#43-expenses-and-income) · [sales](#44-sales) · [invoices](#45-invoices) · [payments](#46-payments)
18. [Reports/exports](#47-reportsexports) · [Notifications](#48-notifications) · [Dashboard](#49-dashboard) · [Settings](#50-settings) · [Team/roles](#51-team-roles-permissions) · [Subscription](#52-subscription-and-entitlements) · [Platform Admin](#53-platform-admin-separation)

---

## 0. Shared building blocks

These recur in many workflows; each workflow names the ones it uses.

### 0.1 Pick the cycle (batch / crop project)

1. `GET /production-cycles?status=active&kind=livestock|crop` (needs `production_cycle.view`). Read `data[].id`, `kind`, `name`, `reference`, `livestock.species.id`, `livestock.current_population`, `crop.*`.
2. A cycle with `status: closed` accepts no new record, sale line, task or schedule (`409 cycle_closed`) — filter to `active` for pickers; reopen first (`POST /production-cycles/{id}/reopen`, `production_cycle.reopen`).
3. Which record types the chosen cycle accepts: open its detail (`GET /production-cycles/{id}`) and read `data.available_record_types[]` (§0.2). The species capability is already applied by the backend; the cycle resource still carries only `livestock.species.{id,code,name}`, and `capability_codes` stay on `GET /master/species` for the breeding/health screens.

### 0.2 Which record types a cycle accepts

`GET /production-cycles/{id}` (also `/summary`) → `data.available_record_types[]`, each `{type, permissions_required}`. The list is built by the same rules `POST /records` applies: the type's `cycle_kind` equals the cycle kind (or is `null` = either), the species has the type's `capability` enabled, and the cycle is `active` (a closed cycle returns `[]`). A type that is not listed is `422` on `type`. The list and the write responses of cycles do **not** carry the field — read it on the detail, and refetch the detail after close/reopen or a platform capability change.

Then, per listed type, check permissions (§0.3) against `GET /farm → membership.permissions`, and take labels/fields/measurement from the `GET /master/record-types` row with the same `type`.

### 0.3 Permissions per record type (`permissions_required`)

Every record schema (`GET /master/record-types`, `GET /record-types/{type}/schema`, and each entry of `available_record_types`) carries the authoritative list; the legacy `permission` field (only `record.create` / `record.adjust`) is unchanged and is no longer enough on its own:

```json
"permissions_required": {
  "always": ["record.create", "inventory.use"],     // egg_collection, milk: they always write a stock-in
  "when_inventory_linked": [],                      // feed_use, planting, fertilizer/pesticide_application, crop_harvest: ["inventory.use"] when details.inventory is sent
  "when_correcting": ["record.reverse"]             // when corrects_record_id is sent
}
```

Offer a type when the member holds every code in `always`; additionally require `when_inventory_linked` when the form links stock, and `when_correcting` for a replacement. `population_adjustment` needs `record.adjust` instead of `record.create`. Roles: `farm_worker` has `record.create` + `inventory.use`; `finance` and `vet` have neither, so egg/milk types are hidden for them (the endpoint answers `403 forbidden`).

### 0.4 Unit dropdown for any quantity

`record-type.measurement.dimension` (or the inventory item's `dimension`) → `GET /master/units?dimension=<dim>` (never "all units"). Pre-select `GET /settings/units`. Send `components: [{quantity: "3", unit: "crate"}, {quantity: "14", unit: "piece"}]` (max 10 parts; temperature max 1).

**Package units** (`bag`, `crate`, …) resolve only through a conversion:

* record with `details.inventory` (linked stock): the **item's own** conversions apply; sending `details.context` too is `422`.
* `egg_collection`/`milk`: an explicit `details.context` wins; otherwise the Eggs/Milk item's conversions (`context_type: inventory_item`, `context_id` = `inventory_item_id` from `GET /inventory/output-balances`).
* otherwise an explicit `details.context {type: crop_type|custom|inventory_item, id}`.
* Preview before submit: `POST /measurements/normalize {components, context}` (`context.type` accepts `crop_type`, `custom`, `inventory_item`). Without a conversion the submit is `422 conversion_not_configured` → offer "Define package size" → `POST /settings/package-conversions` (`measurement.manage`). Note: the endpoint's own docblock lists only `crop_type|custom` for the `context_type` filter; the enum also has `inventory_item` (verified).

### 0.5 Storage location picker

`GET /storage-locations` (active by default, `location.view`). Rules for stock paths:

* Required explicitly for items; **optional** with `output: eggs|milk` and for `egg_collection`/`milk`/incubation: one active store → used; none → "Main Store" created on IN paths only; several → `422` on the location field → **show the picker**. OUT with several stores also needs a choice. Take store ids with stock from `GET /inventory/output-balances` → `by_storage_location`.

### 0.6 Item kind of a stock item (decides which `inventory-options` list to render)

Every inventory item (`GET /inventory/items`, `GET /inventory/items/{id}`) carries two stable fields:

```text
kind              "feed" | "eggs" | "milk" | "general"   → index into reasons.by_item_kind[kind]
is_system_managed true for the farm's automatic Eggs / Milk output items (resolved by the system), false for every item the farmer created
```

Never derive the kind from the name or category. For Eggs/Milk screens skip the item picker entirely: the screen *is* the kind; call with `output: "eggs"|"milk"` (stock-in/out, sale and purchase lines) or let the record/incubation resolve it. A manually created item called "Eggs" is adopted only if produce/piece/not lot-tracked/active (then `kind: eggs`, `is_system_managed: true`); otherwise a separate "Eggs (farm output)" item is created and the manual one stays `kind: general` / `is_system_managed: false`.

### 0.7 The `inventory-options` ladder (Feed / Eggs / Milk / General)

```text
GET /master/inventory-options                       (inventory.view; once per session, cache)
 → data.reasons.by_item_kind.<feed|eggs|milk|general>.{in[], out[]}     (display order; render labels)
 → user picks one entry (code)
 → entry.route.kind / method / path
      "inventory"        → POST /inventory/stock-in | stock-out            (entry.manual === true)
      "record"           → POST /records  with entry.route.record_type     (egg_collection | milk | feed_use)
      "breeding_project" → POST /breeding-projects  (+ field consume_egg_stock)   [out: incubation; in: returned = cancel]
      "sale"             → POST /sales  (stock line)
      "transfer"         → POST /inventory/transfers
      "adjustment"       → POST /inventory/adjustments
      (every route has `path` relative to /api/v1 and `url` = the absolute /api/v1/… form)
      entry.also[]       → alternative that books more (purchase → POST /purchases)
 → entry.creates_operational_record tells you whether a record row appears
```

Rules verified in `StockReasonCatalogue`:

* Use `by_item_kind` (authoritative, item-aware), **not** the flat `reasons.in/out` (`reasons.flat_lists.deprecated: true`): the flat list's `manual` only means "not system-only", so `production` is `manual: true` there although eggs/milk production is automatic. Each flat entry has `manual_for_kinds` (e.g. `production` → `["feed"]`) if you must read the flat list.
* Route forms: `route.path` is relative to `/api/v1` (`/records`); `route.url` is the same route absolute (`/api/v1/records`). `GET /tasks/{id}/record-prefill` and dashboard `quick_add[]` keep their absolute `endpoint` and now also carry `url` (= `endpoint`) and `path` (relative). Pick `url` (or `path`) once and use it everywhere.
* The route carries no payload hints beyond `record_type` / `field` / `workflow`; payload details are below and in Postman.
* `manual: false` reasons are `422` on the manual endpoints. Never try them there.

### 0.8 After every successful write

Replace local state with the response resource and refetch per workflow ("Refetch"). Idempotency key: one per submit (integration guide §12). Derived numbers (population, stock, balances, outstanding) are never patched locally.

---

## 1. Application bootstrap, auth, onboarding

1. **Intent:** open the app / sign in / sign up / create the first farm.
2. **Fetch:** `GET /auth/csrf-cookie` (204) → `GET /auth/me`.
3. **Read:** `data.next_action`, `data.user.platform_role`, `data.farm.id` (default farm) and `data.farms[]` (`{id, name, role}` of every farm the user actively belongs to).
4. **Effect:** `next_action` is the router: `verify_email` → OTP; `marketplace` → seller dashboard (farm-less shop member, `GET /marketplace/my/shops`); `complete_farm_setup` → farm name (with an "open a marketplace shop instead" option → `POST /marketplace/shops`); `no_active_farm` → "no farm" screen; `none` → app. `data.marketplace.shop_count` tells farm users that they also have shops. `platform_role` ≠ null → show the Platform Admin area (separately, §53).
5. **Dependent:** when `none`: choose the active farm from `farms[]` (remembered choice if still listed, else `data.farm`; send it as `X-Farm-Id` when it is not the default), then `GET /farm` (farm + `membership.permissions`), `GET /subscription/entitlements`, optionally `GET /locales`, `GET /translations/{locale}`, `GET /me/preferences`, then `GET /dashboard`.
6. **Conditional:** invited users: register/login with the invited email → verify → `POST /invitations/accept {token}` (skips farm setup; `meta.accepted_farm_id`). Google: `POST /auth/google {credential}`.
7. **Final mutations:** `POST /auth/register`, `POST /auth/login`, `POST /auth/email/verify {code}`, `POST /auth/email/resend`, `POST /onboarding/farm {name}`, `POST /auth/logout`; reset: `POST /auth/password/forgot` → `/verify-otp` (keep `reset_token` in memory) → `/reset`.
8. **Payload:** Postman folders `01 — Authentication`, `02 — Onboarding & Account`; Flow 1, Flow 2.
9. **Backend does:** register starts a session + sends OTP; onboarding creates farm + Owner membership + default plan subscription (Nigeria / `Africa/Lagos` / `NGN` / English) and marks the user onboarded forever.
10. **DO NOT:** call `POST /onboarding/farm` for `no_active_farm` (`409 already_onboarded`); derive routing from HTTP status; ask for country/currency/timezone/operations at onboarding.
11. **Refetch:** `/auth/me` after every auth call; `/farm` + `/subscription/entitlements` after role/plan change or accepted invitation.
12. **Needs:** none (verified user). Farm endpoints answer `403 email_verification_required` / `onboarding_required` / `no_active_farm` until the state is right.
13. **States:** `429` + `Retry-After` on OTP resend (60 s); `401 invalid_credentials` is identical for wrong email/password; `403 account_suspended`; `419 session_expired` (refetch csrf, retry once).
14. **Postman:** Flow 1, Flow 2, Flow 13 (invitation).
* **Multi-farm (V1):** plans may limit farms (Free = 1) while paid users can belong to several. Show a switcher only when `farms.length > 1`. Switching is client-side: store the chosen `farms[].id` → send `X-Farm-Id` on every request → `GET /farm` → `GET /subscription/entitlements` → clear and refetch farm-scoped data. `/auth/me` never lists permissions (they come from `GET /farm → membership.permissions` of the selected farm). A removed membership disappears from `farms[]` and its id is `403 farm_access_denied`.

## 2. Farm setup and farm operations

1. **Intent:** rename the farm; say which kinds of production it runs (optional).
2. **Fetch:** `GET /farm` (name, `timezone`, `currency`, `membership`); `GET /farm/operations`; `GET /master/farm-operations`.
3. **Read:** `farm.name`, `farm.timezone` (use for "today"), `membership.permissions`; operations: `data[].selected`, `.available`, `.category`, `.tracking_model`, `meta.farm_operations_configured`.
4. **Effect:** `available` = usable now (everything while nothing is selected). **Hint only:** the backend does not reject a cycle for an unselected operation (verified in `CycleService`); use `selected`/`available` to order and de-emphasise options, never to block.
5. **Dependent:** species picker uses `GET /master/species?operation=<code>&available=1`; crops `GET /master/crops?available=1`.
6. **Conditional:** show edit only when `can('farm.update')`.
7. **Final mutations:** `PATCH /farm {name}` (only name editable); `PUT /farm/operations {operation_ids: [uuid…]}` (empty array clears).
8. **Payload:** Postman `03 — Farms, Team & Access`, `05 — Master Data → Set the farm's operations`.
9. **Backend does:** nothing else; never deletes data.
10. **DO NOT:** send country/currency/timezone/language (ignored/rejected); make operations part of onboarding.
11. **Refetch:** `/farm`, `/farm/operations`, `/dashboard` (its `operations.relevant` drives livestock/crop blocks).
12. **Needs:** `farm.view` / `farm.update`.
13. **States:** empty selection is valid.
14. **Postman:** folder 03, 05.

## 3. Locations and production areas

1. **Intent:** describe where things are (optional; zero places is valid).
2. **Fetch:** `GET /master/location-types`; `GET /locations`, `GET /production-areas`, `GET /storage-locations` (paginated; follow `meta.last_page` to build a tree).
3. **Read:** type codes grouped per kind; each place `id`, `name`, `type`, `parent_id`, path, `is_active`.
4. **Effect:** three kinds, three endpoints: **locations** (site/parent), **production areas** (where animals/crops are: `type` ∈ house, pen, pond, field, plot, other), **storage locations** (stores). A production area's `parent_id` must be a *location*.
5. **Dependent:** production-area picker in cycle forms; storage picker in every stock form (§0.5).
6. **Conditional:** show manage buttons only with `location.manage`.
7. **Final mutations:** `POST|PATCH /locations`, `/production-areas`, `/storage-locations`. No DELETE — deactivate with `is_active: false` (needs active descendants moved first).
8. **Payload:** Postman folder 07; Flow S requests 9–12.
9. **Backend does:** names unique per kind/parent, normalised, including inactive rows; depth ≤ 7.
10. **DO NOT:** model a delete; send `farm_id`.
11. **Refetch:** the place list; cycles that reference a deactivated place still show it.
12. **Needs:** `location.view` (all roles), `location.manage`.
13. **States:** `409 duplicate_location`, `location_inactive`, `location_depth_exceeded`.
14. **Postman:** folder 07, Flow S.

## 4. Create livestock batch

1. **Intent:** "Start a batch".
2. **Fetch:** `GET /master/farm-operations?category=livestock` → pick an operation → `GET /master/species?operation=<code>` → `GET /master/species/{id}/breeds` → optionally `GET /production-areas`.
3. **Read:** operation `id`, `tracking_model` (`population`); species `id`, `capability_codes`; breeds `id`, `source` (`system`/`farm`).
4. **Effect:** species `capability_codes` tell which later forms will appear (eggs, milk, incubation…). Show "+ Add custom breed" (`POST /custom-breeds`) only with `master_data.manage`. The catalogue ships with few/no system breeds — empty lists are valid.
5. **Dependent:** breed list depends on species; production area optional.
6. **Conditional:** `kind` = `livestock` requires `species_id`, `initial_population`, `start_date`; `breed_id` and `production_area_id` optional.
7. **Final mutation:** `POST /production-cycles` (`kind: "livestock"`, `name`, `operation_type_id`, `species_id`, `breed_id?`, `production_area_id?`, `initial_population`, `start_date`, `expected_end_date?`, `notes?`).
8. **Payload:** Postman `08 — Production Cycles → Start a livestock batch`; Flow 3 request 4.
9. **Backend does:** creates the cycle (`BAT-YYYY-#####`) and the opening population movement (+`initial_population`); enforces the `active_cycles` limit in the same transaction.
10. **DO NOT:** send `current_population`, `reference`, `status`, `farm_id` (422); compute population in the client.
11. **Refetch:** `GET /production-cycles/{id}`, list, `/dashboard`, `/subscription/usage` if you show limits.
12. **Needs:** `production_cycle.create`; entitlement limit `active_cycles`.
13. **States:** `409 plan_limit_reached` (`details.limit/usage/remaining`) → upsell; `409 duplicate_cycle_name` (also closed cycles); `422` operation/species mismatch; baseline fields are immutable afterwards (`409 baseline_locked`).
14. **Postman:** Flow 3.

## 5. Create crop (planting) project

1. **Intent:** "Start a crop project".
2. **Fetch:** `GET /master/farm-operations?category=crop` → `GET /master/crops` → `GET /master/crops/{id}/varieties` → `GET /master/planting-reference` → optionally `GET /production-areas`.
3. **Read:** `crop.id`; `material_types[].code` (what is planted); `unit_types[].code` (how it is counted).
4. **Effect:** planting **units**, planting **material quantity** and land **area** are three separate measurements. The form asks `initial_planting_units` (whole count) and optionally `area` (area dimension → `GET /master/units?dimension=area`). Never ask for or infer seed quantity here.
5. **Dependent:** varieties depend on the crop (may be empty; `POST /custom-varieties` with `master_data.manage`).
6. **Conditional:** `kind: "crop"` requires `crop_type_id`, `planting_material_type`, `planting_unit_type`, `initial_planting_units`, `planting_date`.
7. **Final mutation:** `POST /production-cycles` (`kind: "crop"`).
8. **Payload:** Postman `Start a crop project`; Flow 4 request 6.
9. **Backend does:** creates `CRP-YYYY-#####`; baseline units immutable; no population ledger (crops have none); no stock consumed.
10. **DO NOT:** treat planting units as seed quantity; send `material_quantity`.
11. **Refetch:** `GET /production-cycles/{id}` and later `GET /production-cycles/{id}/crop`.
12. **Needs:** `production_cycle.create`; `active_cycles`.
13. **States:** as §4.
14. **Postman:** Flow 4.

## 6. Record Activity discovery

The "Record activity" button is a three-step discovery, not a fixed menu.

1. **Intent:** "Record something that happened".
2. **Fetch (in order):** (a) cycle (§0.1) — or start from the dashboard's `quick_record[]`; (b) `GET /production-cycles/{id}` → `available_record_types[]`; (c) `GET /master/record-types` (labels, forms) — the record-type list is cached; the cycle detail is read per cycle.
3. **Read:** `available_record_types[].{type, permissions_required}`; per type row `type`, `permissions_required`, `measurement`, `inventory` (`mode`, `direction`, `output`, …), `area_fields`, plus the legacy `permission`, `inventory_effect_enabled`, `inventory_automatic`, `inventory_output`.
4. **Effect:** list the types in `available_record_types` whose `permissions_required` the member holds (§0.2 + §0.3). Stock-moving types (`inventory` ≠ `null`) are the ones that also change an inventory balance.
5. **Dependent:** the chosen `type` → `GET /record-types/{type}/schema` (note: **not** under `/master`) for the form (§7).
6. **Conditional:** hide types whose `permissions_required.always` the member lacks (egg/milk without `inventory.use`, `population_adjustment` without `record.adjust`); for optional stock links, hide the "Take from stock" section without `inventory.use`.
7. **Final mutation:** `POST /records` (§7–§30).
8. **Payload:** Postman `09 — Operational Records`, `14 — Crop Operations`.
9. **Backend does:** see each type.
10. **DO NOT:** treat `dashboard.quick_record` as the full list — it is at most 6 types and excludes corrections (`reversal`, `livestock_sale`, `breeding_outcome`, `population_adjustment`); do not offer `reversal`, `livestock_sale`, `breeding_outcome` as record types (not writable).
11. **Refetch:** per record type below.
12. **Needs:** `record.view` to read types; `record.create` to write.
13. **States:** `422` on `type` (kind/capability mismatch), `409 cycle_closed`.
14. **Postman:** folder 09 (`List record types…`, `Get one record type schema`).

## 7. Dynamic record forms

1. **Intent:** render the form for one record type.
2. **Fetch:** `GET /record-types/{type}/schema` (or reuse the row from `/master/record-types`).
3. **Read:** `fields` (key → list of rules, e.g. `["required","string","max:500"]`), `measurement {dimension, display_unit, normalized_unit, required, components_max}` or `null`, `area_fields`, `population_effect`, `permissions_required`, `inventory` (or `null`), and the legacy `inventory_*` flags.
4. **Effect:** each `fields` key is an input under `details.<key>`; `required` is in the rules (`required_without:details.inventory` means "required unless stock is linked"). `measurement` ≠ null → show a quantity control (`details.components`); `measurement.required: false` → optional, but required once a `context`/`inventory` is chosen. Units: §0.4. Unknown `details` keys are `422`, so render only what the schema lists plus `components`/`context`.
5. **Dependent:** unit list per `measurement.dimension`; stock item picker for linkable types (§13–§15, §28–§30); storage picker (§0.5).
6. **Conditional:** read the `inventory` block. `mode: "optional_link"` → optional "Take from stock" / "Add to stock" section: `fields` says which `details.inventory` keys exist (`item_id`, `storage_location_id` are `required` once the object is sent; `lot_id` optional; `lot {code, expires_on}` optional on harvest) → send `details.inventory {item_id, storage_location_id, lot_id?}`. `mode: "automatic_output"` (eggs, milk) → no item section, only an optional store picker → `details.inventory {storage_location_id}` (`output` names which farm stock it feeds). `direction: in|out` says whether stock is added or taken; `item_category` + `dimensions` limit which items are eligible (filter `GET /inventory/items?category=<item_category>` by `dimension`). `area_fields` lists the sub-objects that are an AREA (`treated_area`, `affected_area`: `{quantity, unit}` with an area unit) and are never the stock or planting quantity. `inventory` is `null` for types without a stock effect.
7. **Final mutation:** `POST /records {type, production_cycle_id, recorded_at, idempotency_key, details, notes?}`.
8. **Payload:** Postman folders 09 and 14.
9. **Backend does:** validates strictly against the registry, normalises quantities, writes the record, and (stock-moving types) the movement, in one transaction.
10. **DO NOT:** send `farm_id`, `population_delta`, `measurement`, `created_by` (422); invent record types; patch/delete a record (use `POST /records/{id}/reverse`).
11. **Refetch:** see the per-type sections; always `GET /records?production_cycle_id=`.
12. **Needs:** §0.3 (`permissions_required`).
13. **States:** `409 cycle_closed`, `409 idempotency_conflict`, `422` measurement codes (`conversion_not_configured`, …).
14. **Postman:** folder 09 (`Get one record type schema`, `Get the egg collection record type schema`).
* **Shapes the schema does not spell out (by design):** the compound `components` / `context` objects are documented once, in §0.4 and the Postman bodies; `inventory_required` is always `false` (stock is never mandatory) — use the `inventory` block.
* **Reversal / correction:** `POST /records/{id}/reverse {reason, recorded_at, idempotency_key}` (`record.reverse`; cycle must be open; appends a compensating row, never edits). Correct = reverse, then `POST /records {…, corrects_record_id}`. `breeding_outcome` and `livestock_sale` records are reversed through their owners (`409 reverse_via_breeding_outcome` / `reverse_via_sale`).
* **Attachments:** `POST /records/{id}/attachments` (multipart `file`), `GET …/attachments/{id}` as blob.

---

## 8. Mortality

1. **Intent:** animals died.
2. **Fetch:** cycle (§0.1); schema `mortality`.
3. **Read:** `capability: supports_mortality`; `fields.quantity`, `fields.cause`; `population_effect: decrease`.
4. **Effect:** show only for livestock cycles whose species has `supports_mortality`. No measurement control: `quantity` is a whole head count.
5. **Dependent:** show `livestock.current_population` as the upper bound hint.
6. **Conditional:** none.
7. **Final mutation:** `POST /records` `type: mortality`, `details {quantity, cause}`.
8. **Payload:** Postman `Record mortality`; Flow 3 request 7.
9. **Backend does:** record + population movement −quantity; rejects a result below zero in dated history.
10. **DO NOT:** also adjust population; do not also create a health record for the death (a health record may *link* it via `mortality_record_id`; reversing the health record does not reverse the mortality).
11. **Refetch:** cycle (`current_population`), records, dashboard (`mortality_7d` KPI, insights).
12. **Needs:** `record.create`; species capability `supports_mortality`.
13. **States:** `409 insufficient_population`, `409 cycle_closed`.
14. **Postman:** Flow 3.

## 9. Population adjustment

1. **Intent:** reconcile the head count after a physical count.
2. **Fetch:** `GET /production-cycles/{id}` immediately before showing the form.
3. **Read:** `livestock.current_population` → this is the **expected** value.
4. **Effect:** the user types only the *actual* count; the form sends `expected_population` = the value you just read.
5. **Dependent:** none.
6. **Conditional:** only with `record.adjust` (Owner/Manager). Not in `quick_record`.
7. **Final mutation:** `POST /records` `type: population_adjustment`, `details {expected_population, actual_population, reason}`.
8. **Payload:** Postman `Reconcile population (population adjustment)`; Flow 3 request 8.
9. **Backend does:** appends `actual − expected` as a population movement; stores `details.difference`.
10. **DO NOT:** write population anywhere else; do not cache `expected_population`.
11. **Refetch:** cycle, records, dashboard.
12. **Needs:** `record.create` **and** `record.adjust`.
13. **States:** `409 population_changed` (someone else changed it → refetch and re-ask); `422 recorded_at` (a count cannot precede existing movements).
14. **Postman:** Flow 3.

## 10. Weight

1. **Intent:** sample live weight.
2. **Fetch:** schema `weight`; `GET /master/units?dimension=weight`; `GET /settings/units`.
3. **Read:** `capability: supports_live_weight`, `measurement.dimension: weight`, `fields.sample_size`.
4. **Effect:** weight control + whole-number `sample_size` (animals weighed).
5. **Dependent:** units per dimension.
6. **Conditional:** only species with `supports_live_weight`.
7. **Final mutation:** `POST /records` `type: weight`, `details {components, sample_size}`.
8. **Payload:** Postman `Record sample live weight`.
9. **Backend does:** record only (no stock, no population).
10. **DO NOT:** compute average in the client from the sample and store it elsewhere; the record keeps `measurement.entered` and `normalized` (g).
11. **Refetch:** records.
12. **Needs:** `record.create`; capability.
13. **States:** measurement `422` codes.
14. **Postman:** folder 09.

## 11. Temperature

1. **Intent:** house temperature reading.
2. **Fetch:** schema `temperature`; `GET /master/units?dimension=temperature`.
3. **Read:** `capability: null` (any livestock cycle), `measurement.components_max: 1`.
4. **Effect:** one value + unit only.
5. **Dependent:** units.
6. **Conditional:** `cycle_kind: livestock`.
7. **Final mutation:** `POST /records` `type: temperature`, `details {components:[{quantity, unit}]}`.
8. **Payload:** `Record house temperature`.
9. **Backend does:** record only.
10. **DO NOT:** send two components.
11. **Refetch:** records.
12. **Needs:** `record.create`.
13. **States:** measurement codes.
14. **Postman:** folder 09.

## 12. Water

1. **Intent:** water consumed by the batch.
2. **Fetch:** schema `water`; `GET /master/units?dimension=volume`.
3. **Read:** `measurement.dimension: volume`, `display_unit: l`.
4. **Effect:** volume control.
5. **Dependent:** units.
6. **Conditional:** livestock cycle.
7. **Final mutation:** `POST /records` `type: water`, `details {components}`.
8. **Payload:** `Record water consumption`.
9. **Backend does:** record only.
10. **DO NOT:** confuse with crop `irrigation` (crop cycles, optional measurement).
11. **Refetch:** records.
12. **Needs:** `record.create`.
13. **States:** —
14. **Postman:** folder 09 (and `Reverse a record` uses a water record).

---

## 13. Feed IN

1. **Intent:** feed arrives (bought, donated, received, made on the farm, opening stock).
2. **Fetch:** `GET /master/inventory-options` → `reasons.by_item_kind.feed.in`; `GET /inventory/items?category=feed`; `GET /storage-locations`; `GET /master/units?dimension=weight`.
3. **Read:** feed `in[]` entries: `purchase`, `donation`, `production` ("Produced on farm"), `aid`, `received`, `opening_balance`, `other`, plus `transfer_in`/`adjustment` actions. Each has `route`, `manual`.
4. **Effect:** all feed IN reasons are `manual: true` → `route.kind: inventory`, `POST /inventory/stock-in`. `purchase` has `also[]` → `POST /purchases` (books the expense too). `production` is *home-mixed feed* (valid for feed; it is refused for eggs/milk/produce). `transfer_in`/`adjustment` are other screens (§32, §33).
5. **Dependent:** item (`category: feed`), store, optional lot (only if `tracks_lots`).
6. **Conditional:** lot-tracked items need `lot_id` or `lot {code, expires_on}`; packages (bag) need the item's conversion (§0.4).
7. **Final mutation:** `POST /inventory/stock-in {inventory_item_id, storage_location_id, reason, components, recorded_at, idempotency_key, notes?, lot?}`.
8. **Payload:** Postman `Receive stock (stock-in)`; Flow 5.
9. **Backend does:** one `stock_in` movement (+ lot if given). No operational record, no expense.
10. **DO NOT:** also call `POST /purchases` for the same delivery (that writes its own stock-in); do not create an item with a quantity (`POST /inventory/items` has none).
11. **Refetch:** `GET /inventory/items/{id}`, `/inventory/items/{id}/movements`, items list (`stock`, `is_low_stock`), dashboard `low_stock`.
12. **Needs:** `inventory.manage`. (Farm worker has `inventory.use` only → hide IN.)
13. **States:** `409 item_inactive`, `422 conversion_not_configured`, `422 reason` (system-owned), `409 idempotency_conflict`.
14. **Postman:** `Receive stock (stock-in)` (opening balance), `Receive feed from other sources (stock-in reasons)` (`aid`, `production`, `donation`, `received`); purchase is `Record a purchase (stock + expense)`; Flow 14 request 21.

## 14. Feed OUT

1. **Intent:** feed leaves stock.
2. **Fetch:** `reasons.by_item_kind.feed.out`; feed items; stores.
3. **Read:** `production_use` ("Used for livestock", `creates_operational_record: true`, route `record`/`feed_use`), `sale` (route `sale`), `donation`, `spoiled`, `lost`, `disposal`, `other`, `transfer_out`, `adjustment`.
4. **Effect:** pick the entry, then follow its `route`: `production_use` → §15; `sale` → §44; `donation`/`spoiled`/`lost`/`disposal`/`other` → `POST /inventory/stock-out`; `transfer_out` → §32; `adjustment` → §33.
5. **Dependent:** item, store, lot (lot-tracked: required, no automatic FIFO/FEFO).
6. **Conditional:** feed never shows the legacy `use`/`expired`/`wasted` (they are not in the feed list; `use` on feed is `422`).
7. **Final mutation:** `POST /inventory/stock-out {inventory_item_id, storage_location_id, reason, components, recorded_at, idempotency_key, lot_id?}`.
8. **Payload:** Postman `Donate feed (stock-out)`, `Issue stock (stock-out)` (reason `spoiled`).
9. **Backend does:** one negative `stock_out`; negative stock impossible now or in dated history.
10. **DO NOT:** record "fed to birds" here (double decrement with `feed_use`); do not send `sale`, `production_use`, `incubation` (422).
11. **Refetch:** item + movements, dashboard `low_stock`.
12. **Needs:** `inventory.use`.
13. **States:** `409 insufficient_stock`, `409 lot_expired`, `422`.
14. **Postman:** both above.

## 15. Feed used for livestock

1. **Intent:** "Fed Broiler Batch A with 25 kg".
2. **Fetch:** cycle (§0.1, livestock, species `supports_feed_records`); `GET /inventory/items?category=feed` filtered to `dimension: weight` (a non-weight feed item is `422`); stores; schema `feed_use`.
3. **Read:** `fields.feed_name` (required even when stock is linked — send the item name), `inventory_effect_enabled`, `inventory_direction: out`.
4. **Effect:** two modes: **linked** (pick item+store[+lot] → one entry, one decrement) or **record only** (no `details.inventory`, a pure record with no stock effect — Postman `Record feed use (not linked to stock)`).
5. **Dependent:** item → store with stock → lot.
6. **Conditional:** linked mode needs `inventory.use`; packages resolve through the **item's** conversion; do **not** send `details.context` in linked mode.
7. **Final mutation:** `POST /records` `type: feed_use`, `details {feed_name, components, inventory {item_id, storage_location_id, lot_id?}}`.
8. **Payload:** Postman `Record feed use (linked to inventory)`.
9. **Backend does:** the `feed_use` record **and** a `stock_out` reason `production_use` linked to the record and cycle, in one transaction.
10. **DO NOT:** also call `POST /inventory/stock-out`; never retry with a new key after a timeout.
11. **Refetch:** records, the feed item + movements, dashboard.
12. **Needs:** `record.create` (+ `inventory.use` if linked); species capability `supports_feed_records`.
13. **States:** `409 insufficient_stock`, `409 lot_expired`, `422 details.inventory.item_id` (not feed / not weight).
14. **Postman:** folder 09.
* Reversal: `POST /records/{id}/reverse` compensates stock. A `production_use` movement cannot be reversed from `/inventory/movements/{id}/reverse` (`409 reverse_via_record`).

---

## 16. Eggs IN

1. **Intent:** "Add eggs".
2. **Fetch:** `GET /master/inventory-options` → `reasons.by_item_kind.eggs.in`; `GET /inventory/output-balances`.
3. **Read:** entries `production` (manual **false**, `creates_operational_record: true`, `route: {kind: record, path: /records, record_type: egg_collection}`), `purchase` (also → `/purchases`), `donation`, `received`, `opening_balance`, `other` (all `route.kind: inventory`), plus transfer/adjustment. Note: there is **no `aid`** in the eggs list.
4. **Effect:** source decides the screen: **Produced on farm** → §18; **Purchased / Gift-Donation / Received / Other / Opening balance** → §19.
5. **Dependent:** §18 needs a cycle with `produces_eggs`; §19 needs only a store (optional).
6. **Conditional:** hide "Produced on farm" when the role lacks `record.create`+`inventory.use` or no active cycle's species has `produces_eggs`.
7. **Final mutation:** by source — `POST /records` or `POST /inventory/stock-in {output: "eggs"}`.
8. **Payload:** §18/§19.
9. **Backend does:** §18/§19.
10. **DO NOT:** send `reason: production` to `stock-in` (422 → use the record); never create an `egg_collection` for donated/bought eggs.
11. **Refetch:** `GET /inventory/output-balances`, movements, records (if produced), dashboard.
12. **Needs:** see §18/§19.
13. **States:** —
14. **Postman:** `Inventory option catalogue`; Flow 14 request 1.

## 17. Eggs OUT

1. **Intent:** "Remove eggs".
2. **Fetch:** `reasons.by_item_kind.eggs.out`; `GET /inventory/output-balances`.
3. **Read:** `sale` (route `sale`), `incubation` (route `breeding_project`, `field: consume_egg_stock`), `donation`, `internal_use`, `damaged`, `spoiled`, `lost`, `other`, `transfer_out`, `adjustment`. `data.eggs.available`, `by_storage_location`.
4. **Effect:** `sale` → §21; `incubation` → §22; the rest (`donation`, `internal_use`, `damaged`, `spoiled`, `lost`, `other`) → §20; `transfer_out` → §32; `adjustment` → §33.
5. **Dependent:** the store: take it from `by_storage_location` (only non-zero stores).
6. **Conditional:** `data.eggs.exists === false` or available 0 → disable OUT (a stock-out never creates the item: `409 insufficient_stock`).
7. **Final mutation:** by reason.
8. **Payload:** §20–§22.
9. **Backend does:** one negative movement per event.
10. **DO NOT:** call `stock-out` with `sale` or `incubation` (422).
11. **Refetch:** output-balances, movements.
12. **Needs:** `inventory.use` (stock-out), `sale.create`, `breeding.create` + `inventory.use`.
13. **States:** `409 insufficient_stock`.
14. **Postman:** `Give away eggs (stock-out, no sale)`.

## 18. Egg collection / produced on farm

1. **Intent:** "Collected eggs from Layers A".
2. **Fetch:** `inventory-options` is not needed to submit; the "Produced on farm" entry (§16) routes you here. Fetch: active cycles with `produces_eggs` (§0.1); `GET /record-types/egg_collection/schema`; `GET /master/units?dimension=count` (family `piece`/`egg`: the field is `piece`, packages `crate`/`tray`); `GET /inventory/output-balances` (for `inventory_item_id` to define package sizes); `GET /storage-locations`.
3. **Read:** schema `measurement {dimension: count, display_unit: piece}`, `inventory_automatic: true`, `inventory_output: "eggs"`, `inventory_direction: "in"`; output-balances `eggs.inventory_item_id`.
4. **Effect:** quantity control with compound entry (3 crates + 14 pieces). A store picker appears **only** if the farm has several active stores (otherwise omit `details.inventory`). Preview with `POST /measurements/normalize`.
5. **Dependent:** cycle → species capability; store; package conversion (`POST /settings/package-conversions`, `context_type: inventory_item`, `context_id: eggs.inventory_item_id`) when a crate is used and none exists.
6. **Conditional:** `eggs.exists === false` → no item yet: define the package size on a `custom` measurement context (`POST /settings/measurement-contexts`) and send it as `details.context`, or enter in `piece` only.
7. **Final mutation:** `POST /records` `type: egg_collection`, `details {components, context?, inventory?: {storage_location_id}}`.
8. **Payload:** Postman `Record egg collection (compound quantity)`; Flow 14 request 2.
9. **Backend does:** in one transaction: the `egg_collection` record **and** a `stock_in` (normalised quantity, reason `production`) linked to the record and the cycle; creates the Eggs item and, on a farm with no store, "Main Store" on first use. Quantity 0 records production but moves no stock.
10. **DO NOT call `POST /inventory/stock-in` afterwards** — that would count the eggs twice. Do not create an Eggs item first. Do not read "eggs available" from the dashboard.
11. **Refetch:** `GET /records?production_cycle_id=`, **`GET /inventory/output-balances`**, `GET /inventory/movements?inventory_item_id=`, dashboard (`eggs_today` is *production*; available stock is a different number).
12. **Needs:** `record.create` **and** `inventory.use`; species capability `produces_eggs`.
13. **States:** `422 type` (species lacks capability), `422 conversion_not_configured`, `422` on the store field (several stores, none chosen), `409 cycle_closed`; reversal `409 insufficient_stock` once the eggs left.
14. **Postman:** Flow 14 request 2 (prerequisite: a context with crate = 30 pieces).

## 19. Egg donation / purchase / received

1. **Intent:** eggs arrive that this farm did not produce.
2. **Fetch:** `eggs.in` entries (§16); output-balances; stores.
3. **Read:** entry `code` ∈ `donation`, `purchase`, `received`, `other`, `opening_balance`; `manual: true`; `route.kind: inventory`.
4. **Effect:** one stock-in screen with a "source" dropdown made from those entries. `purchase` also offers `also[]` → **Record a purchase** to book the expense.
5. **Dependent:** store (optional with `output`).
6. **Conditional:** none; no cycle is involved.
7. **Final mutation:** `POST /inventory/stock-in {output: "eggs", storage_location_id?, reason, components, recorded_at, idempotency_key}`. To book the cost as well: `POST /purchases` instead (§42) with a stock line `{kind: "stock", output: "eggs", storage_location_id?, components, amount}` — no item id is needed (the Eggs item is resolved or created exactly as stock-in does).
8. **Payload:** Postman `Receive donated eggs (stock-in, no item setup)`.
9. **Backend does:** one `stock_in` movement; creates the Eggs item/Main Store if missing. **No** `egg_collection` record, ever.
10. **DO NOT:** call `POST /records`; do not combine stock-in and purchase for the same delivery.
11. **Refetch:** output-balances, movements.
12. **Needs:** `inventory.manage`.
13. **States:** `422 reason` for `production`/`returned`.
14. **Postman:** `Receive donated eggs…`, `Receive eggs from other sources (stock-in reasons)` (`received`, `purchase`, `other`, `production` → 422), `Record a purchase of eggs (by output)`; Flow 14 requests 7, 8 and 16.

## 20. Egg damage / spoilage / internal use

1. **Intent:** eggs broken, spoiled, eaten at home, given away, lost.
2. **Fetch:** `eggs.out` entries; output-balances.
3. **Read:** `damaged`, `spoiled`, `internal_use`, `donation`, `lost`, `other` (all `manual: true`).
4. **Effect:** one stock-out screen; reason from the list.
5. **Dependent:** store from `by_storage_location`.
6. **Conditional:** disable when available is 0.
7. **Final mutation:** `POST /inventory/stock-out {output: "eggs", storage_location_id?, reason, components, recorded_at, idempotency_key}`.
8. **Payload:** `Give away eggs (stock-out, no sale)` (reason `donation`).
9. **Backend does:** one `stock_out`; **no** sale, income or record.
10. **DO NOT:** create a negative `egg_collection`; do not use `adjustment` for known events (use it only to correct a count, §33).
11. **Refetch:** output-balances, movements.
12. **Needs:** `inventory.use`.
13. **States:** `409 insufficient_stock`.
14. **Postman:** `Give away eggs…` (`donation`), `Write off or use eggs (stock-out reasons)` (`damaged`, `spoiled`, `internal_use`, `lost`, `sale` → 422, too many → 409); Flow 14 requests 10 and 11.

## 21. Egg sale

1. **Intent:** "Sold 2 crates of eggs".
2. **Fetch:** `GET /inventory/output-balances` (`eggs.exists`, `available`, `by_storage_location`); `GET /contacts?role=customer`; `GET /finance/categories` (optional).
3. **Read:** `eggs.exists`, `eggs.available`; the store ids with stock.
4. **Effect:** a sale screen with a stock line that names `output: "eggs"` — no item id is needed. If `eggs.exists === false` or `available` is 0 there is nothing to sell: disable (the backend would answer `409 insufficient_stock` and create nothing).
5. **Dependent:** store from `by_storage_location` (optional when the farm has exactly one active store); crate size from the **Eggs item's** conversion (`context_type: inventory_item`).
6. **Conditional:** `sellable_categories` (from `inventory-options`) includes `produce` — the Eggs item is produce.
7. **Final mutation:** `POST /sales {contact_id? | customer_name?, recorded_at, idempotency_key, items:[{kind:"stock", output:"eggs", storage_location_id?, components, amount}], invoice?}`. Exactly one of `output` / `inventory_item_id` per stock line (`inventory_item_id` stays supported); both or neither is `422 items.N.output|inventory_item_id`.
8. **Payload:** Postman `Record a sale of eggs (by output)` (+ examples: `409 insufficient_stock`, `422` several stores); Flow 14 request 15.
9. **Backend does:** the sale + lines + one `sale` stock-out per stock line, linked to the line. A sale resolves the EXISTING Eggs item only: it never creates the item, a store or any stock. Books **no income** and **no invoice** (unless the optional `invoice` block is sent).
10. **DO NOT:** also `stock-out` the eggs (reason `sale` is refused); do not book income manually; do not call `POST /invoices` after sending the `invoice` block.
11. **Refetch:** sale, output-balances, movements, sales list, dashboard (`sales_month`).
12. **Needs:** `sale.create` (+ `invoice.create` for the invoice block). `inventory.use` is **not** required.
13. **States:** `409 insufficient_stock` (also when the farm never had eggs), `409 item_inactive`, `422 items.N.storage_location_id` (several active stores, none chosen), `422` amounts (≤ 2 decimals).
14. **Postman:** `Record a sale of eggs (by output)` (folder 17), Flow 14 request 15.

## 22. Egg incubation

See §37 (breeding workflow). Entry from Eggs OUT `incubation` (`route.kind: breeding_project`).

---

## 23. Milk IN

Identical to §16 with `by_item_kind.milk.in` and `output: "milk"`. "Produced on farm" → `route.record_type: milk` (§25); purchase/donation/received/other/opening balance → §26. Milk has **no `aid`** entry either. Capability for production is **`produces_milk`** (seeded for cattle, goat, sheep, camel, water buffalo; a species without it gets `422 type`).

## 24. Milk OUT

Identical to §17 with `by_item_kind.milk.out`: `sale` (route `sale`), `donation`, `internal_use`, `spoiled`, `lost`, `other`, `transfer_out`, `adjustment`. There is no `incubation` and no `damaged` entry for milk. Unit family is volume (`l`, canonical `ml`).

## 25. Milk produced on farm

As §18 with `type: milk`, `capability: produces_milk`, `measurement.dimension: volume` (`GET /master/units?dimension=volume`), `inventory_output: "milk"`. Payload: Postman `Record milk production (creates Milk stock)` (note: it uses `dairy_cycle_id`). Backend: the `milk` record **and** a `stock_in` (reason `production`) in one transaction. **DO NOT** call `stock-in {output: milk}` afterwards. Refetch: records, `output-balances` (`data.milk`), movements. **GAP-09:** the dashboard has an `eggs_today` KPI but no milk-produced or available eggs/milk KPI — read milk production from `GET /records?type=milk` or reports, available stock from `output-balances`.

## 26. Milk purchase / donation

As §19 with `output: "milk"` and `reason` ∈ `purchase`, `donation`, `received`, `other`, `opening_balance`; a purchase with its cost is `POST /purchases` with a stock line `{output: "milk", …}` (unit `l`). Postman: `Receive milk (stock-in, no item setup)` (`opening_balance`, `donation`, `purchase`, `production` → 422); Flow 14 request 17.

## 27. Milk sale / spoilage / internal use

Sale: as §21 with `output: "milk"` and a volume `components` (`l`). Spoilage/internal use/donation/lost: as §20 with `output: "milk"` and reasons `spoiled`, `internal_use`, `donation`, `lost`, `other`. Postman: `Record a sale of milk (by output)`, `Write off or use milk (stock-out reasons)`; Flow 14 requests 18 and 19.

---

## 28. Crop planting input consumption

1. **Intent:** record planting and the seed/material used.
2. **Fetch:** crop cycle (§0.1); schema `planting`; `GET /inventory/items?category=seed_planting_material` (use the exact category code from `inventory-options.categories`); stores; `GET /master/units?dimension=weight` or `count`.
3. **Read:** `fields.units_planted` (planting units, required), `measurement.required: false`, `inventory_dimensions: [weight, count]`, `inventory_direction: out`.
4. **Effect:** two **independent** inputs: planting units (`units_planted`) and — only if taking from stock — material quantity (`components`) with an item/store. The item's dimension fixes the unit family (weight or count).
5. **Dependent:** item → store → lot.
6. **Conditional:** `components` required once `details.inventory` is sent; cumulative planting cannot exceed the cycle's `initial_planting_units`.
7. **Final mutation:** `POST /records` `type: planting`, `details {units_planted, method?, components?, inventory?}`.
8. **Payload:** Postman `Record planting`; Flow 4 request 8.
9. **Backend does:** record + (if linked) `stock_out` of the material quantity.
10. **DO NOT:** treat 800 heaps as 800 kg/tubers; do not send `details.context` with `inventory`; do not call `stock-out`.
11. **Refetch:** `GET /production-cycles/{id}/crop`, seed item + movements, records.
12. **Needs:** `record.create` (+ `inventory.use` if linked).
13. **States:** `422 details.units_planted` (over baseline), `409 insufficient_stock`.
14. **Postman:** Flow 4.

## 29. Fertilizer/agrochemical use

1. **Intent:** applied fertilizer, pesticide, herbicide, etc.
2. **Fetch:** schema `fertilizer_application` / `pesticide_application`; items of category fertilizer/agrochemical; `GET /master/units?dimension=area`; stores.
3. **Read:** `fields.method`, `input_name` (required unless stock is linked), `product_type` (pesticide: insecticide|herbicide|fungicide|other), `treated_area` (area object), `concentration`; `inventory_dimensions: [weight, volume]`.
4. **Effect:** three separate things: **quantity applied** (stock), **treated area** (context only) and **concentration**. The area never feeds stock.
5. **Dependent:** item → store → lot; area unit list.
6. **Conditional:** with `details.inventory`, the item decides weight vs volume and `input_name` defaults to the item name.
7. **Final mutation:** `POST /records` `type: fertilizer_application|pesticide_application`.
8. **Payload:** `Record fertilizer application`, `Record pesticide / herbicide application`; Flow 4 request 11.
9. **Backend does:** record + `stock_out` when linked.10. **DO NOT:** also stock-out; do not enter area as quantity.
11. **Refetch:** crop detail, item + movements, records.
12. **Needs:** `record.create` (+ `inventory.use`).
13. **States:** `409 insufficient_stock`, `422`.
14. **Postman:** folder 14.

## 30. Crop harvest / output

1. **Intent:** harvested produce.
2. **Fetch:** schema `crop_harvest`; items of category `produce`; stores.
3. **Read:** `inventory_direction: in`, `inventory_dimensions: [weight, volume, count]`, `fields.quality`.
4. **Effect:** quantity + optional "Add to stock": produce item + store + optional lot (`details.inventory.lot {code, expires_on}` opens a lot, or `lot_id`).
5. **Dependent:** item → store.
6. **Conditional:** quantity must be > 0.
7. **Final mutation:** `POST /records` `type: crop_harvest`.
8. **Payload:** `Record harvest (into produce stock)`; Flow 4 request 13.
9. **Backend does:** record + `stock_in` (reason `harvest`) when linked.
10. **DO NOT:** also call `stock-in` for the harvest; do not add harvest totals across units — the crop detail returns totals per canonical unit.
11. **Refetch:** `GET /production-cycles/{id}/crop`, produce item + movements, dashboard (`harvest_30d`).
12. **Needs:** `record.create` (+ `inventory.use` if linked). The API does not block harvest during a medicine withdrawal: warn from `GET /health/withdrawals?active=1`.
13. **States:** `422 details.components`.
14. **Postman:** Flow 4.

---

## 31. General inventory stock IN / OUT

1. **Intent:** manage supplies (medicine, fertilizer, seed, tools, …).
2. **Fetch:** `GET /master/inventory-options` (`categories`, `sellable_categories`, `reasons.by_item_kind.general`); `GET /inventory/items` (filters `category`, `search`, `low_stock=1`, `include_inactive=1`); `GET /inventory/lots`.
3. **Read:** `general.in` / `general.out` entries; item `stock`, `stock_unit`, `dimension`, `tracks_lots`, `tracks_expiry`, `is_low_stock`.
4. **Effect:** create item first (`POST /inventory/items`: name, category, `stock_unit`, `tracks_lots`, `tracks_expiry`, `low_stock_threshold?`; **no quantity**), then receive stock. General OUT: `use` ("Used"), `sale`, `donation`, `damaged`, `expired`, `spoiled`, `lost`, `disposal`, `other`; `sale` routes to `/sales`.
5. **Dependent:** unit dropdown by the item's `dimension`; lots by item.
6. **Conditional:** lot-tracked → `lot_id` (OUT) / `lot` (IN); category/unit/lot flags lock after the first movement (`409 item_has_movements`); deactivation needs zero stock (`409 item_has_stock`).
7. **Final mutations:** `POST /inventory/items`, `PATCH /inventory/items/{id}`, `POST /inventory/stock-in`, `POST /inventory/stock-out`.
8. **Payload:** Postman folder 10; Flow 5.
9. **Backend does:** one signed movement per event; balances derived.
10. **DO NOT:** show or send a quantity on items; do not use `adjustment` for normal use.
11. **Refetch:** item, movements, lots, medicines (if medicine), dashboard.
12. **Needs:** `inventory.manage` (create/IN), `inventory.use` (OUT), `inventory.view`.
13. **States:** `409 insufficient_stock`, `item_inactive`, `lot_expired` (`use` of an expired lot: write off with `expired`/`wasted`).
14. **Postman:** Flow 5.

## 32. Transfers

1. **Intent:** move stock between stores.
2. **Fetch:** item (`GET /inventory/items/{id}` → `balances[]` by store/lot); stores.
3. **Read:** `balances[].storage_location_id`, `inventory_lot_id`, `quantity`.
4. **Effect:** source list = non-zero balances; destination = any other active store.
5. **Dependent:** lot (same lot arrives).
6. **Conditional:** `to` ≠ `from`.
7. **Final mutation:** `POST /inventory/transfers {inventory_item_id, from_storage_location_id, to_storage_location_id, components, lot_id?, recorded_at, idempotency_key}` → `201`, `data.movements = [transfer_out, transfer_in]`.
8. **Payload:** `Transfer stock between storage locations`.
9. **Backend does:** both legs atomically with a shared `transfer_group_id`.
10. **DO NOT:** model as stock-out + stock-in.
11. **Refetch:** item balances, movements.
12. **Needs:** `inventory.manage`.
13. **States:** `409 insufficient_stock`; reverse via `POST /inventory/movements/{id}/reverse` (`inventory.adjust`) reverses both legs.
14. **Postman:** folder 10.

## 33. Adjustments

1. **Intent:** physical count differs from the system.
2. **Fetch:** `GET /inventory/items/{id}` → the (store, lot) balance.
3. **Read:** `balances[].quantity` → this is `expected`.
4. **Effect:** user enters `counted`; the client sends both.
5. **Dependent:** store, lot.
6. **Conditional:** `inventory.adjust` only (Owner/Manager).
7. **Final mutation:** `POST /inventory/adjustments {inventory_item_id, storage_location_id, lot_id?, expected, counted, reason, recorded_at, idempotency_key}`.
8. **Payload:** `Reconcile a physical count (adjustment)`.
9. **Backend does:** appends signed `counted − expected`; zero difference is an audited verification.
10. **DO NOT:** send the difference or a new balance; do not use for donation/spoilage.
11. **Refetch:** item, movements.
12. **Needs:** `inventory.adjust`.
13. **States:** `409 stock_changed` (refetch and re-count); `422` count before existing movements.
14. **Postman:** folder 10.

---

## 34. Medicine / health records

1. **Intent:** vaccination, medication, deworming, treatment, disease issue, vet visit.
2. **Fetch:** `GET /master/health-record-types`; `GET /health/medicines`; cycles (livestock); stores; `GET /health/medicines/{id}` for lots.
3. **Read:** type `fields`, `medicines` (`required` | `forbidden`), `cycle_kinds`, `common_fields`.
4. **Effect:** `medicines: required` → show a medicine-lines section (item, store, lot, quantity, optional `dose_per_animal`, `dosage_instructions`, `withdrawal_days`); `forbidden` → hide it. Livestock cycles only.
5. **Dependent:** medicine → lots (lot-tracked: `lot_id` required, expired refused) → quantity units (the item's dimension).
6. **Conditional:** `mortality_record_id` (optional link to an existing mortality record of the same cycle); `animals_affected` ≤ current population; `follow_up_on`.
7. **Final mutation:** `POST /health-records {type, production_cycle_id, recorded_at, idempotency_key, details, medicines[], animals_affected?, follow_up_on?, mortality_record_id?}`.
8. **Payload:** `Record a vaccination (consumes medicine stock)`, `Record a disease issue (no medicines)`; Flow 6.
9. **Backend does:** the health record + one `stock_out` per medicine line from the named lot + the withdrawal window per line, in one transaction. **No population effect.**
10. **DO NOT:** also stock-out the medicine; do not also record mortality "through" the health record (record mortality separately and link it).
11. **Refetch:** health records, `GET /health/medicines/{id}`, `GET /health/withdrawals`, dashboard (`active_withdrawals`).
12. **Needs:** `health.create` (Owner, Manager, Farm Worker, Vet); reversal `health.reverse`; withdrawal default `health.manage`. Finance has no health access.
13. **States:** `409 insufficient_stock`, `409 lot_expired`; reversal `POST /health-records/{id}/reverse`.
14. **Postman:** Flow 6. To book the cost as an expense: `POST /finance/transactions` `source {type: health_record, id}` (§43).

## 35. Medicine lots and withdrawal behaviour

1. **Intent:** know what stock and which windows are active.
2. **Fetch:** `GET /health/medicines` and `/health/medicines/{id}` (stock by lot with expiry), `GET /inventory/lots?inventory_item_id=`, `GET /health/withdrawals?active=1&production_cycle_id=`.
3. **Read:** lot `expires_on`, balances; withdrawal `ends_at`, `days`, `source` (`explicit` | `item_default`).
4. **Effect:** list lots soonest-expiry first; show an "in withdrawal" badge on the cycle while any window is active.
5. **Dependent:** medicine profile `PUT /health/medicines/{id}/profile` (default withdrawal days; applies to **future** lines only).
6. **Conditional:** per-line `withdrawal_days` overrides the default.
7. **Final mutation:** `PUT /health/medicines/{id}/profile`.
8. **Payload:** `Set a medicine's default withdrawal period`.
9. **Backend does:** stores the window on each line; reversed records stop counting.
10. **DO NOT:** expect the API to block a sale/harvest/slaughter during a window — it does not; the UI must warn from the active-window list. No automatic FIFO/FEFO lot choice.
11. **Refetch:** medicine, withdrawals.
12. **Needs:** `health.view`; `health.manage` for the profile.
13. **States:** `409 lot_expired`.
14. **Postman:** Flow 6.

---

## 36. Breeding projects

1. **Intent:** start a breeding project (incubation or pregnancy).
2. **Fetch:** cycle (livestock); species capabilities (§0.1): `supports_incubation` / `supports_pregnancy` (both need `supports_breeding`); `GET /master/species/{id}/capabilities` for `reference` (incubation/gestation days).
3. **Read:** `capabilities[].enabled` and `.reference`.
4. **Effect:** offer **Incubation** only if `supports_incubation`, **Pregnancy** only if `supports_pregnancy`. `eggs_set` is required for incubation and forbidden for pregnancy.
5. **Dependent:** optional parents (`dam`/`sire` cycles); manual expected date or window.
6. **Conditional:** a species with no numeric reference gets `expectation.type: none` unless the user supplies `expected_date` or `expected_from`+`expected_to`.
7. **Final mutation:** `POST /breeding-projects {production_cycle_id, workflow, start_date, eggs_set?, females_bred?, expected_offspring?, expected_*?, parents?, notes?, idempotency_key}`.
8. **Payload:** `Start a breeding project (incubation)`; Flow 7.
9. **Backend does:** the project (`BRD-YYYY-#####`), frozen biological reference, derived expectation. Nothing touches population or stock (unless `consume_egg_stock`, §37).
10. **DO NOT:** treat expectations as outcomes; never send `reference_snapshot`/`status`.
11. **Refetch:** project, milestones, calendar.
12. **Needs:** `breeding.create`.
13. **States:** `422 workflow` (capability), `409 cycle_closed`, `409 project_not_active` on edits.
14. **Postman:** Flow 7.
* Other project calls: `PATCH /breeding-projects/{id}` (active only), `POST …/checks {checked_on, result, fertile_count?}`, `GET …/milestones`, `POST …/cancel {reason, eggs_returned_to_stock?, egg_storage_location_id?}`.

## 37. Incubation (breeding workflow)

1. **Intent:** put eggs into an incubator.
2. **Fetch:** eggs `out` entry `incubation` (§17) → `route.kind: breeding_project`; `GET /inventory/output-balances` (available, `by_storage_location`); a cycle whose species `supports_incubation`.
3. **Read:** `eggs.available`, store ids with stock.
4. **Effect:** a single toggle "Take these eggs from my egg stock" → `consume_egg_stock: true` (+ `egg_storage_location_id` when several stores). Max `eggs_set` = available.
5. **Dependent:** store from `by_storage_location`.
6. **Conditional:** `consume_egg_stock` is incubation-only (`422` on pregnancy); `egg_storage_location_id` only with it.
7. **Final mutation:** `POST /breeding-projects {…, workflow:"incubation", eggs_set, consume_egg_stock:true, egg_storage_location_id?}`.
8. **Payload:** Postman `Start incubation taking eggs from stock`; Flow 14 request 5.
9. **Backend does:** project **and** `stock_out` of `eggs_set` eggs (reason `incubation`, linked to project + cycle) in one transaction. Too few eggs → `409 insufficient_stock` and no project.
10. **DO NOT:** also call `POST /inventory/stock-out` (that reason is refused anyway); do not send the flag when the eggs were already removed another way.
11. **Refetch:** project (`egg_stock`), output-balances, movements.
12. **Needs:** `breeding.create` **and** `inventory.use` (a `vet` can create a plain project but cannot consume stock).
13. **States:** `409 insufficient_stock`; cancel: **no instruction = no inventory increase** — send `eggs_returned_to_stock` (≤ taken, not yet returned) to put eggs back (stock-in reason `returned`); `PATCH eggs_set` raises → takes extra (409 if short), lowers → returns nothing unless `eggs_returned_to_stock`.
14. **Postman:** `Cancel incubation returning eggs to stock`; Flow 14 requests 5, 9.

## 38. Breeding outcomes

1. **Intent:** record the hatch / birth.
2. **Fetch:** `GET /breeding-projects/{id}`.
3. **Read:** `eggs_set`, `expectation`, `status`, `egg_stock`.
4. **Effect:** form: `live_count`, `loss_count?`. Show expected vs actual separately.
5. **Dependent:** none.
6. **Conditional:** one effective outcome per project (`409 project_not_active` after).
7. **Final mutation:** `POST /breeding-projects/{id}/outcomes {live_count, loss_count?, recorded_at, idempotency_key}`.
8. **Payload:** `Record the actual outcome (hatch / birth)`; Flow 7.
9. **Backend does:** `live_count > 0` → **one** `breeding_outcome` operational record + population +`live_count` in the same transaction; `0` adds nothing. Never changes egg stock (eggs left stock when set).
10. **DO NOT:** also record a population adjustment or an egg stock-in; do not reverse the generated record via `/records`.
11. **Refetch:** project, **the cycle** (`current_population`), records, dashboard.
12. **Needs:** `breeding.create`; correction/reversal `breeding.reverse` (`POST …/outcomes/{id}/reverse`).
13. **States:** `409 insufficient_population` on reversal, `422` (`live+loss > eggs_set`).
14. **Postman:** Flow 7.

---

## 39. Tasks

1. **Intent:** plan work that should happen.
2. **Fetch:** `GET /master/task-categories`; `GET /tasks?status=open&due_state=` (`upcoming|due_today|overdue|completed|cancelled`); cycles; `GET /farm/members` (assignee, needs `team.view`).
3. **Read:** task `due_state` (derived, farm-local day), `linked_record_type`, `requires_evidence`, `status`.
4. **Effect:** Owner/Manager see all; other roles see tasks assigned to them/their role/created by them (others 404).
5. **Dependent:** cycle → breeding project; template flow: `GET /work-templates/recommended?production_cycle_id=` → `POST /work-templates/{id}/apply` (once per target, `409 template_already_applied`), `clone` to customise; schedules `POST /schedules` (generate tasks, never records).
6. **Conditional:** `linked_record_type` = a record type code, `health`, `breeding_check` or `breeding_outcome` — says which record will evidence the task.
7. **Final mutation:** `POST /tasks`, `PATCH /tasks/{id}`, `POST /tasks/{id}/cancel`.
8. **Payload:** folder 13; Flow 8.
9. **Backend does:** creates the task only; templates/schedules create **tasks** only (first 30 days; topped up daily by `work:generate-tasks`).
10. **DO NOT:** expect a task to create a mortality/feed/vaccination/income record.
11. **Refetch:** tasks, calendar, dashboard `work`.
12. **Needs:** `task.view`, `task.manage`; `task.complete` for completion.
13. **States:** `409 task_not_open`, `409 cycle_closed`, `409 template_already_applied`.
14. **Postman:** Flow 8.

## 40. Calendar

1. **Intent:** see work and milestones in a range.
2. **Fetch:** `GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD` (max 92 days); dashboard strip `GET /dashboard/calendar?from=&days=`.
3. **Read:** items of type task (full task incl. `due_state`) and milestones (`cycle_start`, `cycle_expected_end`, `breeding_expected`, `breeding_expected_window`, `health_follow_up`).
4. **Effect:** milestones are read-only and are not tasks or records; they appear only with the matching view permission and are omitted when filtering by category/assignee.
5–7. n/a (read-only).
8. **Payload:** `Calendar (tasks and milestones)`.
9. **Backend does:** nothing.
10. **DO NOT:** treat a milestone as a task or try to complete it.
11. **Refetch:** after task create/complete/cancel or template apply.
12. **Needs:** `task.view`.
13. **States:** range > 92 days is `422`.
14. **Postman:** Flow 8 request 11.

## 41. Task completion that creates or links operational records

The only correct order is **record first, then complete**.

1. **Intent:** "Mark done" a task that has a `linked_record_type`.
2. **Fetch:** `GET /tasks/{id}/record-prefill`.
3. **Read:** `endpoint`, `method`, `evidence_type`, `prefill {production_cycle_id, breeding_project_id?, type?, recorded_at, date}`. `409 task_has_no_linked_record` if none.
4. **Effect:** open the *normal* record form for that type pre-filled with the context. `endpoint` is absolute (`/api/v1/records`, `/api/v1/health-records`, `/api/v1/breeding-projects/{id}/checks|outcomes`); `url` repeats it and `path` is the same route relative to `/api/v1` (use either consistently); for a `breeding_*` link the URL needs `breeding_project_id` — verify it is present.
5. **Dependent:** the record form (§7, §34, §38).
6. **Conditional:** `requires_evidence: true` → completion is `422` without evidence.
7. **Final mutations (in order):** (1) the actual record endpoint → returns `id`; (2) `POST /tasks/{id}/complete {evidence: {type: evidence_type, id}, note?, completed_at?}`.
8. **Payload:** Flow 8 requests 6–8.
9. **Backend does:** (1) as for that record (including any stock effect, e.g. `egg_collection` → stock-in); (2) only marks the task completed and links the evidence. Completion **never** creates a record, movement or finance entry.
10. **DO NOT:** call `complete` expecting a record to appear; do not reuse one record as evidence for two tasks (`409 evidence_already_linked`); evidence must be this farm's, not reversed, matching type and cycle.
11. **Refetch:** task, tasks list, calendar, dashboard work block, plus the record's own refetch list.
12. **Needs:** `task.complete` and visibility of the task; `record.create` (and the record's own extras) for step 1.
13. **States:** `409 task_already_completed` (different evidence), `409 cycle_closed`, `422` evidence rules.
14. **Postman:** Flow 8.

---

## 42. Purchases

1. **Intent:** bought stock and/or services.
2. **Fetch:** `GET /contacts?role=supplier`; `GET /finance/categories`; items; stores; optionally cycles.
3. **Read:** item `id`, `dimension`; category ids.
4. **Effect:** lines: `kind: stock` (item, store, components, lot, `amount`) or `non_stock` (description, `amount`). `record_expense` (default books the expense).
5. **Dependent:** units by item dimension; packages via the **item's** conversion.
6. **Conditional:** `record_expense: false` → later `POST /expenses {source:{type:"purchase", id}}` for **exactly** the total. Eggs or milk bought: send the stock line as `{kind:"stock", output:"eggs"|"milk", storage_location_id?, components, amount}` instead of an item id (exactly one of the two; `inventory_item_id` stays supported). The output item is resolved or created and the store follows the stock-in rules (only active store; none → "Main Store"; several → `422 items.N.storage_location_id`).
7. **Final mutation:** `POST /purchases`.
8. **Payload:** `Record a purchase (stock + expense)`, `Record a purchase of eggs (by output)`; Flow 9, Flow 14 request 16. Cancel: `POST /purchases/{id}/cancel`.
9. **Backend does:** one `stock_in` (reason `purchase`) per stock line + one expense of the total linked to the purchase, atomically.
10. **DO NOT:** also `POST /inventory/stock-in` or `POST /expenses` for the same purchase (`409 finance_already_recorded`).
11. **Refetch:** purchase, items/movements, finance summary/transactions, dashboard.
12. **Needs:** `purchase.create` (finance role has it; farm worker does not).
13. **States:** `409 insufficient_stock` on cancel if stock was used, `409 contact_inactive`.
14. **Postman:** Flow 9.

## 43. Expenses and income

1. **Intent:** record money in/out that is not a sale payment or purchase.
2. **Fetch:** `GET /finance/categories`; `GET /contacts`; cycles; to book an existing event: its id.
3. **Read:** category `id`, `direction`.
4. **Effect:** `POST /expenses` / `POST /income` are shortcuts for `POST /finance/transactions`; optional `source {type: operational_record|health_record|purchase, id}` = "record as expense" (cycle taken from the source).
5. **Dependent:** none.
6. **Conditional:** a purchase's expense is booked by the purchase itself.
7. **Final mutation:** `POST /expenses` | `POST /income`; reverse `POST /finance/transactions/{id}/reverse`.
8. **Payload:** folder 16; Flow 9 request 8.
9. **Backend does:** one ledger row; money never moves stock.
10. **DO NOT:** book income for a sale here (income is booked by **payments**, §46); do not book an expense for a stocked purchase.
11. **Refetch:** transactions, `GET /finance/summary`, dashboard.
12. **Needs:** `finance.create`, `finance.reverse`, `finance.view`.
13. **States:** `409 finance_already_recorded` (`details.transaction_id`), `409 reverse_via_purchase`.
14. **Postman:** folder 16.

## 44. Sales

1. **Intent:** sell stock, livestock or other lines.
2. **Fetch:** `GET /contacts?role=customer`; stock: `GET /inventory/items` (categories in `inventory-options.sellable_categories`: `produce`, `feed`) and `GET /inventory/output-balances` for eggs/milk (sell them with `output`, no item id); livestock: active cycles with `current_population`.
3. **Read:** `sellable_categories`; item ids/balances.
4. **Effect:** line kinds `stock` (`inventory_item_id` **or** `output: eggs|milk`, store, lot?, components, amount — exactly one of item/output; an `output` line sells existing stock only and creates nothing), `livestock` (cycle, `head_count`, amount), `other` (description, amount). Medicine, seed, agrochemicals and supplies are `422` on a stock line.
5. **Dependent:** store with stock, lot (lot-tracked), cycle.
6. **Conditional:** optional `invoice {issue_date?, due_date?, notes?}` ("Save & create invoice", needs `invoice.create`); `corrects_sale_id` (needs `sale.cancel`).
7. **Final mutation:** `POST /sales`.
8. **Payload:** `Record a sale (stock + livestock lines)`, `Record a sale and issue its invoice in one step`, `Record a sale of eggs (by output)`, `Record a sale of milk (by output)`; Flow 10, Flow 14.
9. **Backend does:** the sale + one `sale` stock-out per stock line + one `livestock_sale` population exit per livestock line, atomically. **No income, no invoice** (unless `invoice` block). The default income category is derived from the lines: livestock-only → `livestock_sales`, produce-only → `crop_sales`, anything else (including feed) → `other_income`.
10. **DO NOT:** manually stock-out or adjust population for the sold items; do not add income.
11. **Refetch:** sale, sales list, items/output-balances, cycle (`current_population`), dashboard.
12. **Needs:** `sale.create`; `invoice.create` for the block. Cancel `POST /sales/{id}/cancel` (`sale.cancel`).
13. **States:** `409 insufficient_stock`/`insufficient_population`/`lot_expired`/`cycle_closed`; cancel: `409 sale_has_payments` (reverse payments first).
14. **Postman:** Flow 10.

## 45. Invoices

1. **Intent:** issue a customer document for a sale.
2. **Fetch:** `GET /sales/{id}` (`payment_status`, existing live invoice).
3. **Read:** `payment_status` (`uninvoiced`, `unpaid`, `partially_paid`, `paid`), invoice summary.
4. **Effect:** show "Issue invoice" only for `uninvoiced`, non-cancelled sales.
5. **Dependent:** none.
6. **Conditional:** `POST /sales/{id}/invoice` (sale in the URL; `sale_id` in the body is `422`) or `POST /invoices` (sale in the body).
7. **Final mutation:** the call above; `POST /invoices/{id}/void`; `GET /invoices/{id}/pdf` as blob.
8. **Payload:** `Issue the invoice for a sale`; Flow 10.
9. **Backend does:** snapshots customer, seller and lines; no income.
10. **DO NOT:** issue twice (`409 invoice_exists`); do not book income here.
11. **Refetch:** sale, invoice.
12. **Needs:** `invoice.create`, `invoice.view`, `invoice.void`.
13. **States:** `409 invoice_has_payments` on void, `409 sale_cancelled`.
14. **Postman:** folder 17.

## 46. Payments

1. **Intent:** money received for an invoice.
2. **Fetch:** `GET /invoices/{id}` (`outstanding`).
3. **Read:** `outstanding`, `payment_status`, `amount_paid`.
4. **Effect:** default amount = `outstanding`; partial payments are normal.
5. **Dependent:** none.
6. **Conditional:** invoice not void.
7. **Final mutation:** `POST /invoices/{id}/payments`; reverse `POST /payments/{id}/reverse`.
8. **Payload:** `Record a payment (partial)`; Flow 10.
9. **Backend does:** payment + **one income** ledger entry (the only place sale income is booked).
10. **DO NOT:** `POST /income` for the same money.
11. **Refetch:** invoice, sale, payments, **finance summary**, dashboard.
12. **Needs:** `payment.create`, `payment.reverse`.
13. **States:** `409 payment_exceeds_balance` (`details.outstanding`), `409 invoice_void`, `409 payment_already_reversed`.
14. **Postman:** Flow 10.

---

## 47. Reports/exports

1. **Intent:** run and export reports.
2. **Fetch:** `GET /reports`.
3. **Read:** per report `code`, `filters`, `applies_to`, `relevant`, `available` (+ `unavailable_reason: feature_not_available`), `exportable`.
4. **Effect:** hide `relevant: false`; show `available: false` as an upsell; show Export only when `exportable`.
5. **Dependent:** run `GET /reports/{code}?from=&to=&…` (farm-local days; defaults to last 30 days).
6. **Conditional:** `advanced_reports` plan feature for advanced reports; `data_export` for exports.
7. **Final mutation:** `POST /reports/exports {report, format, filters, idempotency_key}` → `202 queued` → poll `GET /reports/exports/{id}` → `GET …/download`.
8. **Payload:** folder 19; Flow 11.
9. **Backend does:** background job writes a private file; 7-day expiry; only the requester may download.
10. **DO NOT:** sum quantities of different units; poll faster than the integration guide §20 backoff.
11. **Refetch:** export status; `GET /reports/exports`.
12. **Needs:** `report.view` + each report's data permissions; `report.export`; features above.
13. **States:** `403 feature_not_available`, `409 export_not_ready|export_failed`, `410 export_expired`.
14. **Postman:** Flow 11.

## 48. Notifications

`GET /notifications?unread=1` (`1`/`0`, never `true`) → `meta.unread_count`; `POST /notifications/{id}/read`; `POST /notifications/read-all`; `GET|PATCH /notification-preferences` (`channels`, `types`; types depend on permissions). Read-only side effect; generated by a scheduled job — poll. Each has `source {type,id,reference}` to link. Needs any active membership. Postman: Flow 12, folder 20. Do not wait for push (none in V1).

## 49. Dashboard

1. **Intent:** landing screen.
2. **Fetch:** `GET /dashboard` (one request); `GET /dashboard/calendar`, `GET /insights?severity=`.
3. **Read:** `sections` (booleans per area the viewer may see), `operations.relevant {livestock, crop}`, `kpis[]` (`code`, `value`, `unit`, `breakdown`), `quick_record[]`, `quick_add[]` (`code`, `permission`, `method`, absolute `endpoint`, plus `url` = `endpoint` and relative `path`), `empty_state`, `work`, `production`, `insights`, `recent_activity`.
4. **Effect:** render a block only if present/`sections.<x>` is true; nothing 403s. `empty_state.suggested_actions` for new farms.
5. **Dependent:** each card links to its own screen.
6. **Conditional:** a crop-only farm gets no egg/mortality cards; workers get no money cards.
7. **Final mutation:** none (read model; nothing stored).
8. **Payload:** `Get the dashboard`.
9. **Backend does:** computes on request.
10. **DO NOT:** use `eggs_today` as stock (it is production); treat `quick_record` as complete (max 6). **GAP-09 (open):** no milk-produced or available eggs/milk KPI.
11. **Refetch:** after any ledger action (integration guide §18).
12. **Needs:** any active member.
13. **States:** null blocks (`work`, `calendar`, `production`) when the permission is missing.
14. **Postman:** folder 18; Flow 1 request 8.

## 50. Settings

| Setting | Read | Write | Permission |
|---|---|---|---|
| Farm name | `GET /farm` | `PATCH /farm {name}` | `farm.update` |
| Operations | `GET /farm/operations` | `PUT /farm/operations` | `farm.update` |
| Units | `GET /settings/units` | `PUT /settings/units {preferences:{weight:"kg"}}` | `measurement.manage` |
| Measurement contexts | `GET /settings/measurement-contexts` | `POST|PATCH` | `measurement.manage` |
| Package conversions | `GET /settings/package-conversions` | `POST|PATCH` | `measurement.manage` |
| Custom breeds / varieties | `GET /custom-breeds|varieties` | `POST|PATCH` | `master_data.manage` |
| Notifications | `GET|PATCH /notification-preferences` | | any member |
| My language | `GET|PATCH /me/preferences` | `{locale}` | verified user |
| My profile/password | `GET|PATCH /account`, `PUT /account/password` | | verified user |

Preferences change pre-selected units/language only, never stored data. Details/payloads: Postman folders 06, 02, 22.

## 51. Team, roles, permissions

`GET /farm/members` (`team.view`), `GET /roles` (assignable roles for the caller), `POST /farm/invitations {email, role}` (`team.invite`; token emailed only, 7 days; counts toward `team_members`), `GET /farm/invitations`, `POST …/{id}/resend`, `DELETE …/{id}`, `PATCH /farm/members/{membershipId} {role}` (`team.update_role`), `DELETE /farm/members/{membershipId}` (`team.remove`). Path id is the **membership** id. Owner can never be assigned; no self role change; last owner protected. Permissions for UI: `GET /farm → membership.permissions` (no `GET /permissions`). Roles: owner, manager, farm_worker, finance, vet. Postman: Flow 13. States: `409 plan_limit_reached`, `403 insufficient_role`, `409 last_owner`.

## 52. Subscription and entitlements

`GET /subscription/entitlements` (any member): `features {advanced_reports, data_export}`, `limits {team_members, active_cycles}`. `GET /subscription` and `/subscription/usage` (`subscription.view`), `POST /subscription/cancel|resume` (`subscription.manage`). Disable or upsell on `403 feature_not_available`, `403 subscription_inactive`, `409 plan_limit_reached`; never hard-code plan names. Plan changes are made by a Platform Admin in V1 (no checkout). Entitlements gate: new/reopened cycles (`active_cycles`), invitations (`team_members`), advanced reports, exports. Postman folder 04.

## 53. Platform Admin separation

Show only when `user.platform_role` is `admin` or `support`. Separate route tree and client: `/platform-admin/*`, no `X-Farm-Id`, different login (a farm Owner is not an admin). `support` is read-only (`403 platform_write_forbidden`). Admin granted only by `php artisan platform:grant-admin`. Surfaces: plans/prices/entitlements, reference data and species capabilities, work templates (draft/publish/archive), settings, feature flags, users (suspend/restore), farms (support overview, change plan), audit logs. Never expose in the farm UI. Postman folder 90; [`PHASE-18-PLATFORM-ADMIN.md`](PHASE-18-PLATFORM-ADMIN.md).

## 54. Marketplace listings (Phase 23)

Full rules: `PHASE-23-MARKETPLACE-LISTINGS.md`. No farm context; sign-in + verified email.

**Create & publish:** `GET /marketplace/product-options` → choose kind, product, unit (+ `package` for containers) → `POST /marketplace/shops/{shop}/listings` (draft, `201`) → optional `POST …/images` (multipart) or `catalog_image_id` → `POST …/publish` (owner/manager; `409 shop_not_active` if the shop is not approved, `422 listing_incomplete` with `details.missing`). Effect: the listing is on `GET /public/marketplace/listings` immediately; **no stock moves**, no admin approval needed.

**Edit/stay live:** `PATCH …/listings/{listing}` with `version`. Changing `unit` requires restating `package`. `409 stale_listing` → reload.

**Pause / archive / restore:** `POST …/pause|archive|restore`; idempotent. Archived → `restore` → draft → `publish`. Draft-only `DELETE`.

**Staff:** can create/edit drafts and photos; Publish/Pause/Archive/Restore and edits to live listings answer `403` (hide them using `abilities`).

**Moderation (platform admin):** `POST /platform-admin/marketplace/listings/{listing}/restrict {reason}` hides it at once; `…/lift-restriction` → `paused` (the seller must publish again). There is no "hide" endpoint.

**Shop suspended/closed:** every published listing of the shop disappears from the public feed immediately and returns when the shop is reinstated/reopened — no listing changes.

**Inventory-linked listing:** the seller may link an eggs/milk/feed/produce item of the shop's farm; the console shows live `on_hand` vs the declared quantity. Selling still goes through the normal farm Sales workflow; an accepted deal (later phase) will never create a Sale or deduct stock by itself.

## 26. Marketplace offers & purchase intents (Phase 24)

Contract: `PHASE-24-MARKETPLACE-OFFERS.md`. No farm context needed. Not chat, escrow or checkout.

**A. Buyer makes an offer**
1. **Intent:** "Make an offer" on a negotiable listing. 2. **Fetch:** `GET /marketplace/listings/{slug}/offer-status`. 3. **Read:** `can_offer`, `blocked_reason`, `rules.*`. 4. **Effect:** none. 5. **Dependent:** the listing's `negotiable` flag, the buyer's attempts. 6. **Conditional:** `can_offer=false` -> disable the form and explain `blocked_reason` (`offer_pending` -> show the open offer; `offer_limit_reached` -> offer "Proceed at listed price"; `offer_already_accepted` -> link to the offer). 7. **Final mutation:** `POST /marketplace/listings/{slug}/offers`. 8. **Payload:** `{quantity:"10", unit_price:"7000"}`. 9. **Backend does:** validates (quantity, floor, ceiling) before using an attempt, snapshots the listing, stamps `expires_at`. 10. **DO NOT:** compute the floor yourself with floats; show seller contact; treat the response as a purchase. 11. **Refetch:** offer-status, my enquiries. 12. **Needs:** sign-in + verified email; not a member of the shop. 13. **States:** `422 unit_price|quantity`, `409 offer_pending|offer_limit_reached|offer_already_accepted|listing_not_negotiable`, `403 cannot_negotiate_own_listing`, `404`. 14. **Postman:** folder 25 -> "Make an offer".

**B. Buyer proceeds at the listed price**
1. **Intent:** "Proceed at listed price". 2. **Fetch:** the public listing. 3. **Read:** `price`, `quantity.min_order/available`. 4. **Effect:** records interest only. 5.-6. **Conditional:** works for fixed-price and negotiable listings. 7. **Final mutation:** `POST …/purchase-intent`. 8. **Payload:** `{quantity:"10"}`. 9. **Backend does:** idempotent upsert of one intent per buyer and listing. 10. **DO NOT:** say "ordered" or "paid"; reserve stock. 11. **Refetch:** my enquiries. 12. **Needs:** sign-in. 13. **States:** `422 quantity`, `403`, `404`. 14. **Postman:** "Proceed at listed price".

**C. Seller answers an offer**
1. **Intent:** "Accept" / "Reject". 2. **Fetch:** `GET /marketplace/shops/{shop}/offers?status=pending`. 3. **Read:** `terms`, `listing_snapshot`, `expires_at`, `respondable`, `buyer.name`. 4. **Effect:** accept = agreement in principle; reject = closes the offer (uses the buyer's attempt). 5. **Dependent:** the listing must be live for accept. 6. **Conditional:** hide the buttons without `offer.respond`; disable Accept when the listing is not live. 7. **Final mutation:** `POST …/offers/{offer}/accept|reject`. 8. **Payload:** none. 9. **Backend does:** locks shop -> listing -> offer, records history and audit; repeats are idempotent. 10. **DO NOT:** promise stock or reveal contact. 11. **Refetch:** the offer and the list. 12. **Needs:** `offer.respond` (owner, manager). 13. **States:** `409 offer_expired|offer_voided|offer_not_pending|listing_unavailable|shop_not_active`, `403`, `404`. 14. **Postman:** "Shop: accept an offer".

## 27. Marketplace deals & contact exchange (Phase 25)

Contract: `PHASE-25-MARKETPLACE-DEALS.md`. No farm context needed. Not an order, escrow or checkout; no payment, stock or sale is created.

**A. Buyer confirms an accepted offer**
1. **Intent:** "Confirm deal". 2. **Fetch:** `GET /marketplace/my/offers/{offer}`. 3. **Read:** `status=accepted`, `deal`, `deal_confirmation.{open,deadline}`, `terms`, `listing.currently_live`. 4. **Effect:** creates the deal and releases contact. 5. **Dependent:** shop active, listing live, declared quantity still enough. 6. **Conditional:** show only when `deal=null` and `deal_confirmation.open`; ask for `fulfilment_method` when the listing offers both. 7. **Final mutation:** `POST /marketplace/my/offers/{offer}/deal`. 8. **Payload:** `{fulfilment_method?:"pickup", contact_phone?:"+2348055501234"}`. 9. **Backend does:** locks listing -> offer, re-validates, freezes the offer's price/quantity/total, writes history + audit; a repeat returns the same deal. 10. **DO NOT:** show payment, promise stock, add a delivery charge to the total. 11. **Refetch:** the offer and `GET /my/deals/{deal}`. 12. **Needs:** sign-in, the offer's buyer. 13. **States:** `409 deal_window_closed|offer_not_accepted|deal_terms_stale|quantity_unavailable|listing_unavailable|shop_not_active|seller_contact_unavailable`, `422 fulfilment_method|contact_phone`, `404`. 14. **Postman:** Flow 17, "Buyer: confirm the accepted offer as a deal".

**B. Fixed-price purchase (seller confirms, then buyer confirms)**
1. **Intent (seller):** "Confirm this request". 2. **Fetch:** `GET /marketplace/shops/{shop}/purchase-intents`. 3. **Read:** `is_current`, `can_confirm`, `terms`, `deal_flow`. 4. **Effect:** creates a confirmation only - no deal, no contact. 5. **Dependent:** price/unit unchanged, listing live, quantity within declared availability. 6. **Conditional:** gate on `deal.respond`; require `fulfilment_method` for `both`; `delivery_charge` only for seller delivery on an "agreed separately" listing, omit if unknown. 7. **Final mutation:** `POST /marketplace/shops/{shop}/purchase-intents/{intent}/confirm`. 8. **Payload:** `{fulfilment_method:"seller_delivery", delivery_charge?:"2500"}`. 9. **Backend does:** shop -> listing -> intent lock, stamps `expires_at` (72h default), audit; same terms again returns the open one. 10. **DO NOT:** call this a deal. 11. **Refetch:** the intent list. 12. **Needs:** `deal.respond`. 13. **States:** `409 intent_stale|confirmation_pending|intent_already_converted|quantity_unavailable|listing_unavailable|shop_not_active`, `422`, `403`, `404`. 14. **Postman:** Flow 17, "Seller: confirm the purchase request".
1. **Intent (buyer):** "Confirm deal". 2. **Fetch:** `GET /marketplace/my/enquiries` (or `GET /my/deal-confirmations/{id}`). 3. **Read:** `confirmation.terms`, `confirmation.fulfilment`, `confirmation.expires_at`, `confirmable`. 4. **Effect:** creates the deal and releases contact. 5. **Dependent:** nothing changed on the listing since. 6. **Conditional:** show only while `confirmable`; require an explicit acceptance of the terms. 7. **Final mutation:** `POST /marketplace/my/deal-confirmations/{id}/confirm`. 8. **Payload:** `{accept_terms:true, contact_phone?:"…"}`. 9. **Backend does:** locks listing -> intent -> confirmation, re-validates, freezes the confirmed terms; a stale listing voids the confirmation. 10. **DO NOT:** let the buyer edit terms. 11. **Refetch:** enquiries and the deal. 12. **Needs:** sign-in, the confirmation's buyer. 13. **States:** `409 confirmation_lapsed|confirmation_withdrawn|confirmation_voided|confirmation_stale|quantity_unavailable|listing_unavailable|shop_not_active`, `422 accept_terms`, `404`. 14. **Postman:** Flow 17, "Buyer: confirm the seller's terms".

**C. Complete, cancel or report a deal**
1. **Intent:** "Mark as completed" / "Cancel deal" / "Report a problem". 2. **Fetch:** `GET /marketplace/my/deals/{deal}` or `GET /marketplace/shops/{shop}/deals/{deal}`. 3. **Read:** `status`, `completion.state`, `can.*`, `cancellation`, `my_reports`. 4. **Effect:** complete = this side's **self-reported** confirmation (the deal completes only when both have; never automatically); cancel = ends the deal and contact access; report = records a complaint and changes nothing. 5. **Dependent:** the other side's confirmation. 6. **Conditional:** buttons from `can.*`; cancel needs a reason code. 7. **Final mutation:** `POST …/deals/{deal}/complete|cancel|report`. 8. **Payload:** `{}` / `{reason:"changed_mind", note?}` / `{target:"other_party", reason:"no_show", description?}`. 9. **Backend does:** locks the deal row only; idempotent; writes history and audit. 10. **DO NOT:** call completion verified, restore stock, refund, or tell the other party about a report. 11. **Refetch:** the deal. 12. **Needs:** buyer, or `deal.respond` for the shop. 13. **States:** `409 deal_not_open`, `403`, `404`, `422`. 14. **Postman:** Flow 17.

**D. Read the other party's contact**
1. **Intent:** "Show contact details". 2. **Fetch:** `GET …/deals/{deal}/contact` on click only. 3. **Read:** `contact.channels`, `contact.preferred_contact_method`, `contact.pickup` (pickup deals only), `notice`. 4. **Effect:** none besides the audit record. 5. **Dependent:** deal not cancelled. 6. **Conditional:** hide when `contact_available=false`; staff cannot see it. 7. **Final mutation:** none (read). 8. **Payload:** none. 9. **Backend does:** records who read which field names, audits, rate-limits (30/min). 10. **DO NOT:** cache, log or prefetch contact; show the address for delivery deals. 11. **Refetch:** none. 12. **Needs:** buyer or `deal.respond`. 13. **States:** `409 deal_contact_unavailable`, `403`, `404`, `429`. 14. **Postman:** Flow 17, "Buyer reads the seller's contact" / "Seller reads the buyer's contact".
