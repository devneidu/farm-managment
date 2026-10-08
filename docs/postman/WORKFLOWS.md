# Postman workflows

Executable end-to-end flows in the Postman folder **99 — Workflows**. Each flow is built from the same requests (and the same bodies/Tests scripts) as the reference folders, in the order below, and was **executed against the real application on a fresh database** to prove it runs and to capture the saved examples. Flows share one environment, so run them **in order** the first time (Collection Runner, environment *Farm Management — Local*). Manual inputs: the emailed OTP codes (`otp_code`, `reset_otp_code`) and the invitation token (`invitation_token`) — with `MAIL_MAILER=log` read them from `storage/logs/laravel.log`.

Quick rules: `login-admin`/`login-farmer` requests switch the cookie session between the platform admin and the farm owner; CSRF is automatic (run **Initialise CSRF cookie** once; every request then carries the visible headers `Origin: {{frontend_origin}}` and, on writes, `X-XSRF-TOKEN: {{xsrf_token}}`, filled from the cookie by the collection pre-request script - nothing to paste); ids are captured into environment variables automatically.

| # | Flow | Requests | Prerequisites |
|---|---|---|---|
| 1 | Email registration & onboarding | 8 | None (fresh environment). |
| 2 | Login & farm context | 7 | Flow 1. |
| S | Demo farm setup (run once after Flow 1) | 22 | Flow 1 (platform admin account optional for the first four requests). |
| 3 | Livestock batch & daily activity | 11 | Flows 1-2 and Flow S (needs `breed_id`, `area_id`). |
| 4 | Crop project | 14 | Flow S (`store_id`, `item_seed_id`, `item_fert_id`, `item_yam_id`). |
| 5 | Inventory | 10 | Flow S (`store_id`). |
| 6 | Health & medicine | 13 | Flow S (`item_med_id`, `lot_med_id`, `store_id`) and Flow 3 (`cycle_id`). |
| 7 | Breeding | 9 | Flow 3 (`cycle_id`). |
| 8 | Tasks & templates | 11 | Flows 1 and 3 (`user_id`, `cycle_id`). |
| 9 | Purchase → inventory → finance | 9 | Flows 3 and 5 (`item_feed_id`, `cycle_id`, `store_id`). |
| 10 | Sale → invoice → payment | 12 | Flows S and 3 (`item_eggs_id`, `store_id`, `cycle_id`). |
| 11 | Reports & export | 5 | Flows 9-10 (data for the reports) and the `farm-business` plan from Flow S. |
| 12 | Notifications | 4 | Flow 11, plus `php artisan notifications:generate` (and a queue worker) run on the server. |
| 13 | Team invitation | 13 | Flow 1 and the plan change from Flow S (Free allows only 3 team members). |
| 14 | Feed, eggs and milk stock | 22 | Flows 1, S, 3 and 5 (`cycle_id`, `store_id`, `item_feed_id` with stock). Creates its own measurement context and crate conversion. |
| 15 | Marketplace listings | 48 | None besides a platform admin account (`admin_email` / `admin_password`). Registers its own farm-less seller (`seller_email`). Manual input: `otp_code`. Run with Newman `--working-dir docs/postman` (photo upload). |
| 16 | Marketplace negotiation | 79 | None besides a platform admin account (`admin_email` / `admin_password`). Registers its own staff, manager, buyer and seller (`staff_email`, `manager_email`, `buyer_email`, `neg_seller_email`). Manual input: `otp_code` before each of the four *Verify … email* requests. |
| 17 | Marketplace deals | 92 | None besides a platform admin account (`admin_email` / `admin_password`). Registers its own staff, manager, two buyers and a seller (`deal_staff_email`, `deal_manager_email`, `deal_buyer_email`, `deal_buyer2_email`, `deal_seller_email`). Manual input: `otp_code` before each of the five *Verify … email* requests. |
| 18 | Marketplace reports & moderation | 38 | Flow 17 (a published listing, an active shop, an accepted offer) and the platform admin account. Uses the Flow 17 buyer; no new registrations. |
| 19 | Marketplace seller plans & promotions (Paystack not exercised) | 30 | Flows 17 and 18 (the seller shop and its listing) and the platform admin account. Switches both monetisation flags on, then off again. |

## Flow 1 — Email registration & onboarding

**Goal.** Create an account, verify the email, create the first farm and land on the dashboard.

**Prerequisites.** None (fresh environment).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Initialise CSRF cookie | `GET /auth/csrf-cookie` (204) |  |
| 2 | Register with email and password | `POST /auth/register` (201) |  |
| 3 | Verify email with OTP | `POST /auth/email/verify` (200) |  |
| 4 | Get current auth state (me) | `GET /auth/me` (200) |  |
| 5 | Complete farm setup (onboarding) | `POST /onboarding/farm` (201) | `farm_id`, `user_id` |
| 6 | Get current user after onboarding | `GET /auth/me` (200) |  |
| 7 | Get current farm (with my role and permissions) | `GET /farm` (200) | `membership_owner_id` |
| 8 | Get the dashboard | `GET /dashboard` (200) |  |

**Variables produced.** `farm_id`, `user_id`, `membership_owner_id`.

**Chain of effects**

```
Register -> user (unverified) + session + OTP email
   -> Verify OTP -> email verified
   -> Onboard (farm name) -> farm + Owner membership + Free subscription
   -> Dashboard (empty state)
```

**Expected state changes**

- A user account is created (unverified) and the browser session starts at registration.
- Verification sets the email as verified; onboarding creates the farm, an Owner membership and a default Free-plan subscription and marks the user onboarded (permanently).
- The dashboard of a brand-new farm has no KPI cards and an `empty_state` with suggested actions.

**Business rules to notice**

- `next_action` goes `verify_email` -> `complete_farm_setup` -> `none` (a farm-less user who opens a Marketplace shop gets `marketplace` instead of `complete_farm_setup`; see folder 01 examples); farm endpoints answer 403 (`email_verification_required`, `onboarding_required`) until each step is done.
- OTP: 6 digits, 10 minutes, single use, 5 wrong attempts invalidate it; resend has a 60 s cooldown (so the flow skips the resend request; it is documented in the reference folder).
- Only the farm name is asked at onboarding; country, timezone, currency and language are defaults.

**What the frontend should learn**

- Route on `data.next_action` after every auth call.
- The CSRF cookie and a stateful `Origin` are required before the first POST.
- Permissions arrive from `GET /farm`, not from `/auth/me`.

## Flow 2 — Login & farm context

**Goal.** Sign back in, read the auth state, resolve the active farm (and see how X-Farm-Id works) and load the dashboard.

**Prerequisites.** Flow 1.

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Initialise CSRF cookie | `GET /auth/csrf-cookie` (204) |  |
| 2 | Log out | `POST /auth/logout` (200) |  |
| 3 | Log in with email and password | `POST /auth/login` (200) |  |
| 4 | Get current user after login | `GET /auth/me` (200) |  |
| 5 | Get current farm (with my role and permissions) | `GET /farm` (200) | `membership_owner_id` |
| 6 | Get the farm with an explicit X-Farm-Id | `GET /farm` (200) |  |
| 7 | Get the dashboard | `GET /dashboard` (200) |  |

**Variables produced.** `membership_owner_id`.

**Chain of effects**

```
Logout -> session ended
   -> Login -> session + auth state
   -> /auth/me -> /farm (role + permissions) -> /farm with X-Farm-Id -> Dashboard
```

**Expected state changes**

- Logout invalidates the session cookie; login creates a new one.
- No data changes.

**Business rules to notice**

- Wrong credentials are `401 invalid_credentials` for both unknown email and wrong password.
- Without `X-Farm-Id` the oldest ACTIVE membership is the farm; with it you choose among your active memberships (a farm you do not belong to is `403 farm_access_denied`).
- `GET /auth/me → data.farms[]` (`{id, name, role}`) lists the farms you actively belong to; there is no switch endpoint: take a `farms[].id`, send it as `X-Farm-Id`, then refetch `GET /farm` and `GET /subscription/entitlements` and clear farm-scoped data. Permissions are not in `/auth/me`.

**What the frontend should learn**

- On load: csrf-cookie -> `GET /auth/me` -> route -> `GET /farm`.
- Store the farm id you want to act on and send it as `X-Farm-Id` on every request for that farm.

## Flow S — Demo farm setup (run once after Flow 1)

**Goal.** Upgrade the demo farm (platform admin), then create the places, stores, inventory items and opening stock the later flows use.

**Prerequisites.** Flow 1 (platform admin account optional for the first four requests).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Log in as the platform admin | `POST /auth/login` (200) |  |
| 2 | List plans | `GET /platform-admin/plans` (200) | `plan_business_id`, `plan_free_id`, `plan_pro_id` |
| 3 | Change a farm's plan | `POST /platform-admin/farms/{farm_id}/subscription/plan` (200) |  |
| 4 | Log in as the farm owner again | `POST /auth/login` (200) |  |
| 5 | List farm operations (production types) | `GET /master/farm-operations` (200) | `op_poultry_id`, `op_crops_id` |
| 6 | List species | `GET /master/species` (200) | `species_chicken_id` |
| 7 | List crops | `GET /master/crops` (200) | `crop_yam_id` |
| 8 | Create a custom breed | `POST /custom-breeds` (201) | `breed_id` |
| 9 | Create a location | `POST /locations` (201) | `location_id` |
| 10 | Create a production area | `POST /production-areas` (201) | `area_id` |
| 11 | Create a storage location | `POST /storage-locations` (201) | `store_id` |
| 12 | Create a second storage location | `POST /storage-locations` (201) | `store2_id` |
| 13 | Create an inventory item (lot-tracked medicine) | `POST /inventory/items` (201) | `item_med_id` |
| 14 | Receive stock (lot-tracked, with expiry) | `POST /inventory/stock-in` (201) | `movement_med_in_id` |
| 15 | List lots with stock on hand | `GET /inventory/lots` (200) | `lot_med_id` |
| 16 | Create an inventory item (produce) | `POST /inventory/items` (201) | `item_eggs_id` |
| 17 | Receive stock (produce) | `POST /inventory/stock-in` (201) |  |
| 18 | Create an inventory item (fertilizer) | `POST /inventory/items` (201) | `item_fert_id` |
| 19 | Receive stock (fertilizer) | `POST /inventory/stock-in` (201) |  |
| 20 | Create an inventory item (planting material) | `POST /inventory/items` (201) | `item_seed_id` |
| 21 | Receive stock (planting material) | `POST /inventory/stock-in` (201) |  |
| 22 | Create an inventory item (harvest produce) | `POST /inventory/items` (201) | `item_yam_id` |

**Variables produced.** `plan_business_id`, `plan_free_id`, `plan_pro_id`, `op_poultry_id`, `op_crops_id`, `species_chicken_id`, `crop_yam_id`, `breed_id`, `location_id`, `area_id`, `store_id`, `store2_id`, `item_med_id`, `movement_med_in_id`, `lot_med_id`, `item_eggs_id`, `item_fert_id`, `item_seed_id`, `item_yam_id`.

**Chain of effects**

```
Platform admin -> change the farm plan (Farm Business)
   -> places: location, production area, storage locations
   -> inventory items + opening stock (each a stock_in movement)
   -> produce / planting-material / fertilizer / medicine items ready for later flows
```

**Expected state changes**

- The demo farm moves to the `farm-business` plan (data export + unlimited cycles/members).
- A breed, a location, a production area and two storage locations exist.
- Items exist with opening stock: vaccine lot NCD-2026-01 (500 ml, expires in 90 days), 600 eggs, 100 kg fertilizer, 200 kg seed tubers; a produce item for yam harvests has zero stock.

**Business rules to notice**

- Plan changes are Platform Admin only (no checkout in V1). If you have no platform admin account skip the first four requests: reports and exports will then be limited by the Free plan.
- Every stock-in is a ledger movement; there is no quantity field on an item.
- Places are optional and have no DELETE; the `parent_id` of an area must be a location of this farm.

**What the frontend should learn**

- Setup data is created with the same endpoints the UI uses.
- The Platform Admin session is a different login: switch back to the farm owner afterwards.

## Flow 3 — Livestock batch & daily activity

**Goal.** Load master data, start a livestock batch, record feed and mortality, reconcile the population and check the derived population and history.

**Prerequisites.** Flows 1-2 and Flow S (needs `breed_id`, `area_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | List farm operations (production types) | `GET /master/farm-operations` (200) | `op_poultry_id`, `op_crops_id` |
| 2 | List species | `GET /master/species` (200) | `species_chicken_id` |
| 3 | Get species capabilities | `GET /master/species/{species_chicken_id}/capabilities` (200) |  |
| 4 | Start a livestock batch | `POST /production-cycles` (201) | `cycle_id`, `cycle_reference` |
| 5 | Show a production cycle | `GET /production-cycles/{cycle_id}` (200) |  |
| 6 | Record feed use (not linked to stock) | `POST /records` (201) |  |
| 7 | Record mortality | `POST /records` (201) | `record_mortality_id` |
| 8 | Reconcile population (population adjustment) | `POST /records` (201) | `record_adjust_id` |
| 9 | Re-read the cycle after records | `GET /production-cycles/{cycle_id}` (200) |  |
| 10 | List operational records | `GET /records` (200) |  |
| 11 | Production summary | `GET /production-cycles/{cycle_id}/summary` (200) |  |

**Variables produced.** `op_poultry_id`, `op_crops_id`, `species_chicken_id`, `cycle_id`, `cycle_reference`, `record_mortality_id`, `record_adjust_id`.

**Chain of effects**

```
Create livestock batch (500 head)
   -> opening population movement +500
   -> Feed use (no population effect)
   -> Mortality -5   -> population movement
   -> Reconciliation -2 (actual 493 vs expected 495)
   -> current_population = 500 - 5 - 2 = 493 (derived), initial_population stays 500
```

**Expected state changes**

- A production cycle (reference `BAT-2026-00001`) with a population ledger starting at +500.
- Feed use: record only. Mortality appends -5; the manager reconciliation appends -2 (actual 493 - expected 495).
- `current_population` is read from the ledger: 493. `initial_population` is still 500.

**Business rules to notice**

- Population can never be written directly; only records, breeding outcomes, sales and reversals move it.
- `recorded_at` is the event time (between the cycle start and now); `created_at` is separate; `idempotency_key` makes retries safe.
- A population adjustment needs `record.adjust` and the `expected_population` must equal the current ledger value (`409 population_changed` otherwise).

**What the frontend should learn**

- Show `current_population` from the API, never `initial_population - deaths` computed in the client.
- Refetch the cycle after every record that can move population.
- Record forms come from `GET /master/record-types` + species capabilities.

## Flow 4 — Crop project

**Goal.** Load crop master data, start a crop project and follow it from planting through harvest to the crop project summary.

**Prerequisites.** Flow S (`store_id`, `item_seed_id`, `item_fert_id`, `item_yam_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | List farm operations (production types) | `GET /master/farm-operations` (200) | `op_poultry_id`, `op_crops_id` |
| 2 | List crops | `GET /master/crops` (200) | `crop_yam_id` |
| 3 | List varieties of a crop | `GET /master/crops/{crop_yam_id}/varieties` (200) |  |
| 4 | Planting reference lists | `GET /master/planting-reference` (200) |  |
| 5 | Create a custom crop variety | `POST /custom-varieties` (201) | `variety_id` |
| 6 | Start a crop project | `POST /production-cycles` (201) | `crop_cycle_id` |
| 7 | Record land preparation | `POST /records` (201) |  |
| 8 | Record planting | `POST /records` (201) | `record_planting_id` |
| 9 | Record establishment check | `POST /records` (201) |  |
| 10 | Record growth stage | `POST /records` (201) |  |
| 11 | Record fertilizer application | `POST /records` (201) |  |
| 12 | Record crop loss | `POST /records` (201) |  |
| 13 | Record harvest (into produce stock) | `POST /records` (201) | `record_harvest_id` |
| 14 | Get crop project detail | `GET /production-cycles/{crop_cycle_id}/crop` (200) |  |

**Variables produced.** `op_poultry_id`, `op_crops_id`, `crop_yam_id`, `variety_id`, `crop_cycle_id`, `record_planting_id`, `record_harvest_id`.

**Chain of effects**

```
Crop project (800 heaps over 2 ha)
   -> land preparation (area only)
   -> planting 800 heaps + 120 kg seed tubers -> stock_out 120 kg   (units != material)
   -> establishment 752/800 -> failed 48, survival "94"
   -> fertilizer 20 kg on 1 ha -> stock_out 20 kg
   -> crop loss 12 units (audit only, no population)
   -> harvest 300 kg -> stock_in 300 kg of produce
   -> Crop project summary (derived read model)
```

**Expected state changes**

- A crop cycle (reference `CRP-2026-00002`) with baseline `initial_planting_units: 800` and area 2 hectare (stored as 20000 sq_m).
- Planting consumes 120 kg of seed tubers from stock; fertilizer consumes 20 kg; the harvest adds 300 kg to the produce item `Fresh Yam Tubers`.
- Crop detail shows planted 800 / remaining 0, survival 94 %, losses 12 by cause, harvest totals in grams (300000 g).

**Business rules to notice**

- Planting units, material quantity and land area are three separate measurements: 800 heaps is not 800 tubers is not 2 hectares.
- Cumulative planting units cannot exceed the baseline; establishment and loss are bounded by it; crops have no population ledger (`population_delta` is 0).
- Stock links are optional; with them each event is one record + one stock movement in one transaction.

**What the frontend should learn**

- Never convert planting units into seed quantity in the UI.
- The crop detail endpoint is a derived read model: refetch it instead of summing records.
- Harvest totals are per canonical unit and are never added across dimensions.

## Flow 5 — Inventory

**Goal.** Create an item, receive stock (with a package conversion), view derived stock, consume stock and read the movement history.

**Prerequisites.** Flow S (`store_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Inventory option catalogue | `GET /master/inventory-options` (200) |  |
| 2 | Create an inventory item (feed) | `POST /inventory/items` (201) | `item_feed_id` |
| 3 | Create a package conversion (inventory item) | `POST /settings/package-conversions` (201) | `conversion_feed_id` |
| 4 | Receive stock (stock-in) | `POST /inventory/stock-in` (201) | `movement_feed_in_id` |
| 5 | List inventory items with derived stock | `GET /inventory/items` (200) |  |
| 6 | Show an inventory item with stock by location and lot | `GET /inventory/items/{item_feed_id}` (200) |  |
| 7 | Issue stock (stock-out) | `POST /inventory/stock-out` (201) | `movement_out_id` |
| 8 | Re-read the item after the stock-out | `GET /inventory/items/{item_feed_id}` (200) |  |
| 9 | Movement history of one item | `GET /inventory/items/{item_feed_id}/movements` (200) |  |
| 10 | Reverse a movement | `POST /inventory/movements/{movement_out_id}/reverse` (201) |  |

**Variables produced.** `item_feed_id`, `conversion_feed_id`, `movement_feed_in_id`, `movement_out_id`.

**Chain of effects**

```
Create item (no quantity)
   -> package conversion: 1 bag = 25 kg (this item)
   -> stock-in 12 bag + 18 kg -> movement +318 kg
   -> item stock (derived) 318 kg
   -> stock-out 25 kg -> movement -25 kg -> 293 kg
   -> movement history -> reverse the stock-out -> reversal movement +25 kg
```

**Expected state changes**

- Item "Layer Grower Mash" (feed, kg). Ledger: +318 kg, -25 kg, then a +25 kg reversal.
- Derived stock follows the ledger (318 -> 293 -> 318).

**Business rules to notice**

- An item has no balance column; the balance is the sum of movements and can never go negative, not even at a past `recorded_at`.
- Package units resolve only through the item's own conversion; without it `422 conversion_not_configured`.
- A reversal never edits history: it appends a movement; a movement created by a record, health event, purchase or sale must be reversed through that parent.

**What the frontend should learn**

- Display `stock.quantity` + unit from the API.
- Generate one idempotency key per form submit and reuse it for retries.
- Compound entry (12 bag + 18 kg) is what the user types; `normalized` is canonical.

## Flow 6 — Health & medicine

**Goal.** Set a withdrawal profile, record a vaccination that consumes medicine stock, verify the stock effect and inspect the withdrawal windows.

**Prerequisites.** Flow S (`item_med_id`, `lot_med_id`, `store_id`) and Flow 3 (`cycle_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | List health record types and field contracts | `GET /master/health-record-types` (200) |  |
| 2 | Set a medicine's default withdrawal period | `PUT /health/medicines/{item_med_id}/profile` (200) |  |
| 3 | List medicines with derived stock and withdrawal profile | `GET /health/medicines` (200) |  |
| 4 | Show a medicine with stock by location and lot | `GET /health/medicines/{item_med_id}` (200) |  |
| 5 | Record a vaccination (consumes medicine stock) | `POST /health-records` (201) | `health_record_id` |
| 6 | Re-read the medicine after the vaccination | `GET /health/medicines/{item_med_id}` (200) |  |
| 7 | List withdrawal windows | `GET /health/withdrawals` (200) |  |
| 8 | Record a disease issue (no medicines) | `POST /health-records` (201) |  |
| 9 | Record a medication (second medicine record) | `POST /health-records` (201) | `health_med2_id` |
| 10 | Reverse a health record | `POST /health-records/{health_med2_id}/reverse` (201) |  |
| 11 | Re-read the medicine after the reversal | `GET /health/medicines/{item_med_id}` (200) |  |
| 12 | List health records | `GET /health-records` (200) |  |
| 13 | Show a health record with its medicine lines | `GET /health-records/{health_record_id}` (200) |  |

**Variables produced.** `health_record_id`, `health_med2_id`.

**Chain of effects**

```
Medicine item + lot (500 ml, Flow S)
   -> default withdrawal profile (7 days)
   -> Vaccination (health record)
        -> stock_out 250 ml from the lot (same transaction)
        -> withdrawal window stored on the line (ends_at)
   -> medicine stock 250 ml  -> withdrawal list
   -> second medication (50 ml) -> reversal -> compensating stock +50 ml, window stops counting
```

**Expected state changes**

- Vaccination record with one medicine line; the lot balance drops 500 -> 250 ml.
- A medication record consumes 50 ml; its reversal returns 50 ml to the same lot and its withdrawal window no longer appears.
- Withdrawal windows list `days`, `source` (`explicit` or `item_default`) and `ends_at`.

**Business rules to notice**

- A health record is something that happened; it never changes population (record mortality separately and optionally link it).
- Medicine lines consume stock through the inventory ledger atomically; any failure leaves no record and no movement.
- Lot-tracked items require `lot_id`; expired lots are refused (`409 lot_expired`); the profile's default withdrawal applies to FUTURE lines only.

**What the frontend should learn**

- Refetch the medicine and the withdrawal list after saving.
- The API does not block sales/harvest during a withdrawal: the UI should warn from the active-window list.

## Flow 7 — Breeding

**Goal.** Start an incubation project, record a candling check and the hatch outcome, and verify the population effect; reverse an outcome.

**Prerequisites.** Flow 3 (`cycle_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Start a breeding project (incubation) | `POST /breeding-projects` (201) | `breeding_project_id` |
| 2 | Show a breeding project | `GET /breeding-projects/{breeding_project_id}` (200) |  |
| 3 | Project milestones | `GET /breeding-projects/{breeding_project_id}/milestones` (200) |  |
| 4 | Record a pregnancy check / incubation candling | `POST /breeding-projects/{breeding_project_id}/checks` (201) |  |
| 5 | Record the actual outcome (hatch / birth) | `POST /breeding-projects/{breeding_project_id}/outcomes` (201) | `outcome_id` |
| 6 | Re-read the cycle after the hatch | `GET /production-cycles/{cycle_id}` (200) |  |
| 7 | Start a breeding project (second attempt) | `POST /breeding-projects` (201) | `breeding_project_b_id` |
| 8 | Record an outcome (second project) | `POST /breeding-projects/{breeding_project_b_id}/outcomes` (201) | `outcome_b_id` |
| 9 | Reverse a breeding outcome | `POST /breeding-projects/{breeding_project_b_id}/outcomes/{outcome_b_id}/reverse` (201) |  |

**Variables produced.** `breeding_project_id`, `outcome_id`, `breeding_project_b_id`, `outcome_b_id`.

**Chain of effects**

```
Breeding project (50 eggs set, 40 expected) -> expectation from the chicken reference (exact date)
   -> candling check (positive, 44 fertile)
   -> outcome 37 live -> ONE breeding_outcome record -> population +37
   -> cycle population 493 -> 530
   -> second project: outcome 20 -> reversal -> compensating -20 -> project active again
```

**Expected state changes**

- Project `BRD-2026-00001` completes with the outcome; the cycle population rises by exactly 37 (493 -> 530).
- The second project's outcome (+20) is reversed (-20): net zero, project returns to `active`.

**Business rules to notice**

- Eggs set and `expected_offspring` are estimates; only a recorded live outcome changes population, once.
- The expected date is a biological reference (exact date or window, never a midpoint), not a guarantee.
- A project holds one effective outcome; the generated record can only be reversed through the outcome.

**What the frontend should learn**

- Show expectation vs actual separately.
- Refetch the cycle for population after an outcome.

## Flow 8 — Tasks & templates

**Goal.** Apply a template, create a task that needs evidence, save the actual record, complete the task with that evidence and check the calendar.

**Prerequisites.** Flows 1 and 3 (`user_id`, `cycle_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Recommended templates for a cycle | `GET /work-templates/recommended` (200) | `template_platform_id` |
| 2 | Apply a template to a cycle | `POST /work-templates/{template_platform_id}/apply` (201) |  |
| 3 | Create a farm template | `POST /work-templates` (201) | `template_farm_id` |
| 4 | Create a task | `POST /tasks` (201) | `task_id` |
| 5 | List tasks | `GET /tasks` (200) |  |
| 6 | Record form prefill for a task | `GET /tasks/{task_id}/record-prefill` (200) |  |
| 7 | Save the actual record that evidences the task | `POST /records` (201) | `record_evidence_id` |
| 8 | Complete a task (with evidence) | `POST /tasks/{task_id}/complete` (200) |  |
| 9 | Verify the completed task | `GET /tasks/{task_id}` (200) |  |
| 10 | Create a recurring schedule | `POST /schedules` (201) | `schedule_id` |
| 11 | Calendar (tasks and milestones) | `GET /calendar` (200) |  |

**Variables produced.** `template_platform_id`, `template_farm_id`, `task_id`, `record_evidence_id`, `schedule_id`.

**Chain of effects**

```
Recommended template -> Apply -> schedules + tasks (no records)
   Create task (should happen, requires evidence)
   -> record-prefill (read-only)
   -> save the ACTUAL record (weight) via POST /records
   -> Complete task with evidence {type, id} -> task completed, evidence linked
```

**Expected state changes**

- Applying the platform template creates schedules and the first 30 days of tasks; nothing is created in the record tables.
- The task is completed with the saved weight record as evidence; the record exists independently.

**Business rules to notice**

- A task is work that SHOULD happen; completion never creates a record, stock movement or finance entry.
- Evidence must belong to the farm, not be reversed, match the linked type and cycle, and can evidence only one task; `requires_evidence` tasks cannot complete without it.
- Templates apply once per target (`409 template_already_applied`).

**What the frontend should learn**

- Open the record form from `record-prefill`, save the record, THEN complete the task.
- Calendar combines tasks and milestones; range max 92 days.

## Flow 9 — Purchase → inventory → finance

**Goal.** Create a supplier, record a purchase and verify both the stock-in and the expense it created.

**Prerequisites.** Flows 3 and 5 (`item_feed_id`, `cycle_id`, `store_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Create a contact (supplier) | `POST /contacts` (201) | `supplier_id` |
| 2 | List income and expense categories | `GET /finance/categories` (200) | `cat_labour_id`, `cat_utilities_id`, `cat_other_income_id` |
| 3 | Record a purchase (stock + expense) | `POST /purchases` (201) | `purchase_id` |
| 4 | Show a purchase with its lines | `GET /purchases/{purchase_id}` (200) |  |
| 5 | Movements caused by a purchase | `GET /inventory/movements` (200) |  |
| 6 | Transactions caused by a purchase | `GET /finance/transactions` (200) |  |
| 7 | Record a purchase without booking the expense | `POST /purchases` (201) | `purchase_b_id` |
| 8 | Record an expense linked to a purchase | `POST /expenses` (201) |  |
| 9 | Finance summary and cycle profitability | `GET /finance/summary` (200) |  |

**Variables produced.** `supplier_id`, `cat_labour_id`, `cat_utilities_id`, `cat_other_income_id`, `purchase_id`, `purchase_b_id`.

**Chain of effects**

```
Supplier contact
   -> Purchase (2 bag + 5 kg feed, delivery line)
        -> ONE stock_in +55 kg (reason purchase)
        -> ONE expense 10,250.45 (source: purchase)
   -> verify stock (movements?purchase_id) and finance (transactions?source_type=purchase)
   -> Purchase without expense (record_expense=false) -> later POST /expenses {source: purchase} for exactly the total
```

**Expected state changes**

- Purchase `PUR-2026-00001`: total 10,250.45 (9,000.00 + 1,250.45); feed stock +55 kg; one expense in the ledger linked to the purchase.
- A second purchase books no expense until `POST /expenses` references it with exactly its total (3,500.00); a duplicate is `409 finance_already_recorded`.

**Business rules to notice**

- A stocked purchase is the only path that writes stock and money together, in one transaction (idempotent by key).
- Money is two-decimal strings; the API sums the lines exactly.
- One live finance transaction per source; cancelling a purchase appends compensating rows (it fails with `insufficient_stock` if the stock was used).

**What the frontend should learn**

- Do not create a separate expense for a purchase that already booked one.
- Refetch inventory and finance after saving.

## Flow 10 — Sale → invoice → payment

**Goal.** Record a sale (stock + livestock), issue its invoice, receive partial then final payments and verify the finance effect.

**Prerequisites.** Flows S and 3 (`item_eggs_id`, `store_id`, `cycle_id`).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Create a contact (customer) | `POST /contacts` (201) | `customer_id` |
| 2 | Record a sale (stock + livestock lines) | `POST /sales` (201) | `sale_id` |
| 3 | Show a sale with its lines | `GET /sales/{sale_id}` (200) |  |
| 4 | Re-read the cycle after the sale | `GET /production-cycles/{cycle_id}` (200) |  |
| 5 | Issue the invoice for a sale | `POST /sales/{sale_id}/invoice` (201) | `invoice_id`, `invoice_reference` |
| 6 | Show an invoice | `GET /invoices/{invoice_id}` (200) |  |
| 7 | Download the invoice as a PDF | `GET /invoices/{invoice_id}/pdf` (200) |  |
| 8 | Record a payment (partial) | `POST /invoices/{invoice_id}/payments` (201) | `payment_id` |
| 9 | Record the final payment | `POST /invoices/{invoice_id}/payments` (201) |  |
| 10 | Income caused by payments | `GET /finance/transactions` (200) |  |
| 11 | Re-read the sale after payment | `GET /sales/{sale_id}` (200) |  |
| 12 | Finance summary after payment | `GET /finance/summary` (200) |  |

**Variables produced.** `customer_id`, `sale_id`, `invoice_id`, `invoice_reference`, `payment_id`.

**Chain of effects**

```
Customer contact
   -> Sale (90 eggs + 12 spent layers = 87,000.00)
        -> stock_out 90 pieces
        -> population exit -12 (livestock_sale record)
        -> NO invoice, NO income yet
   -> Invoice (separate document, snapshot) -> unpaid, outstanding 87,000.00
   -> Payment 30,000.00 -> income row 30,000.00 -> partially paid
   -> Payment 57,000.00 -> income row 57,000.00 -> paid
```

**Expected state changes**

- Sale `SAL-2026-00001` (87,000.00): egg stock -90, cycle population -12.
- Invoice `INV-2026-00001` snapshots customer, seller and lines.
- Two payments create two income rows (30,000.00 and 57,000.00); the sale reads `paid`, outstanding `0.00`; the finance summary includes the income.

**Business rules to notice**

- Sale, invoice and payment are separate resources; ONLY a payment books income (cash basis).
- A payment can never exceed the outstanding amount (`409 payment_exceeds_balance`); cancelling a sale with live payments is `409 sale_has_payments` (reverse the payments first).
- Stock lines sell produce or feed items (the `sellable_categories`); livestock lines remove animals through the population ledger.

**What the frontend should learn**

- Show payment state from the sale/invoice (`payment_status`, `outstanding`), not from your own sums.
- Refetch invoice, sale and finance summary after a payment.
- PDFs are fetched with credentials as a blob.

## Flow 11 — Reports & export

**Goal.** Browse the report catalogue, run a report, request a queued export, poll its status and download it.

**Prerequisites.** Flows 9-10 (data for the reports) and the `farm-business` plan from Flow S.

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | List reports (catalogue) | `GET /reports` (200) | `report_code` |
| 2 | Run a report (income & expense) | `GET /reports/income_expense` (200) |  |
| 3 | Request a report export | `POST /reports/exports` (202) | `report_export_id` |
| 4 | Get an export (status) | `GET /reports/exports/{report_export_id}` (200) |  |
| 5 | Download an export | `GET /reports/exports/{report_export_id}/download` (200) |  |

**Variables produced.** `report_code`, `report_export_id`.

**Chain of effects**

```
Report catalogue -> run report (JSON) -> request export (202 queued)
   -> queue job renders private file -> poll status -> completed -> download (access re-checked)
```

**Expected state changes**

- An export row is created and a background job writes a private CSV; the saved download is the real file.

**Business rules to notice**

- Exports need `report.export`, the report's own permissions and the `data_export` plan feature; the same idempotency key + payload returns the same export (200).
- Files are private to the requesting user and expire after 7 days.

**What the frontend should learn**

- Poll with backoff or listen for the `export_ready` notification.
- Download as a blob; handle 409/410 error JSON.

## Flow 12 — Notifications

**Goal.** Read the notification centre, mark one read, mark all read and verify the unread count.

**Prerequisites.** Flow 11, plus `php artisan notifications:generate` (and a queue worker) run on the server.

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | List my notifications | `GET /notifications` (200) | `notification_id` |
| 2 | Mark a notification read | `POST /notifications/{notification_id}/read` (200) |  |
| 3 | Mark all my notifications read | `POST /notifications/read-all` (200) |  |
| 4 | Verify the unread count | `GET /notifications` (200) |  |

**Variables produced.** `notification_id`.

**Chain of effects**

```
Scheduler: notifications:generate -> notifications in the inbox (deduplicated)
   -> list unread -> mark one read -> mark all read -> unread_count = 0
```

**Expected state changes**

- Notifications are created by the background job, never by an API call; reading changes only `read_at`.

**Business rules to notice**

- Boolean query parameters are `1`/`0`; `unread=true` is rejected with `422`.
- A condition is announced at most once per farm-local day (critical/warning) or week (info) per user.

**What the frontend should learn**

- Poll `meta.unread_count` for the badge; there is no push in V1.
- Notifications point at their source; they never change it.

## Flow 13 — Team invitation

**Goal.** Invite a member, accept the invitation as the invitee, list and change the member, and see the removed-member state.

**Prerequisites.** Flow 1 and the plan change from Flow S (Free allows only 3 team members).

**Request sequence**

| # | Request | Call | Captures |
|---|---|---|---|
| 1 | Invite a team member | `POST /farm/invitations` (201) | `invitation_id` |
| 2 | List open invitations | `GET /farm/invitations` (200) |  |
| 3 | Resend an invitation | `POST /farm/invitations/{invitation_id}/resend` (200) |  |
| 4 | Register the invitee | `POST /auth/register` (201) |  |
| 5 | Verify the invitee's email | `POST /auth/email/verify` (200) |  |
| 6 | Accept a farm invitation | `POST /invitations/accept` (200) | `user_chidi_id` |
| 7 | Get auth state as the invited member | `GET /auth/me` (200) |  |
| 8 | Log in as the farm owner again | `POST /auth/login` (200) |  |
| 9 | List team members | `GET /farm/members` (200) | `membership_chidi_id` |
| 10 | Change a member's role | `PATCH /farm/members/{membership_chidi_id}` (200) |  |
| 11 | Remove a member | `DELETE /farm/members/{membership_chidi_id}` (200) |  |
| 12 | Log in as the invited member | `POST /auth/login` (200) |  |
| 13 | Get auth state after losing every farm | `GET /auth/me` (200) |  |

**Variables produced.** `invitation_id`, `user_chidi_id`, `membership_chidi_id`.

**Chain of effects**

```
Invite (email + token) -> invitee registers with the SAME email + verifies
   -> accept token -> membership created, user onboarded
   -> owner lists members -> changes role -> removes member
   -> member: next_action = no_active_farm
```

**Expected state changes**

- An invitation is created, accepted (membership created) and the member's role changed; after removal the user is still authenticated/onboarded but has no active farm.

**Business rules to notice**

- The token is emailed only (7 days, single use); the invited email must equal the account email (`403 invitation_email_mismatch`).
- Pending invitations count toward the plan's `team_members` limit; owner can never be assigned.
- The path id of member endpoints is the MEMBERSHIP id.

**What the frontend should learn**

- Build the role picker from `GET /roles` (`assignable`).
- Handle `no_active_farm` as a first-class screen.

## Flow 14 — Feed, eggs and milk stock

**Goal.** One real-world event, one entry, every effect: collect eggs, receive eggs from other sources, put eggs into incubation, give some away and write some off, cancel the incubation returning some, sell and buy eggs by `output` (no item id), receive, write off and sell milk by `output`, receive feed from other sources, then donate and sell feed. See `docs/api/FEED-EGGS-MILK-STOCK.md`.

**Prerequisites.** Flows 1, S, 3 and 5 (`cycle_id` — a chicken batch, `store_id`/`store2_id`, `item_feed_id` with stock). The flow creates its own measurement context and crate conversion (request 1-2), so it no longer depends on folder 06.

**Request sequence**

| # | Request | Endpoint | Captures |
|---|---|---|---|
| 1 | Create a measurement context (egg crates) | `POST /settings/measurement-contexts` | `context_id` |
| 2 | Create a package conversion (crate = 30 pieces) | `POST /settings/package-conversions` | `conversion_id` |
| 3 | Inventory option catalogue | `GET /master/inventory-options` | – (read `reasons.by_item_kind`, `manual_for_kinds`, route `url`) |
| 4 | Record egg collection (compound quantity) | `POST /records` | `record_eggs_id`, `record_eggs_movement_id` |
| 5 | Egg and milk available balances (read-only) | `GET /inventory/output-balances` | `egg_item_id`, `milk_item_id` |
| 6 | Show the egg stock item (kind, is_system_managed) | `GET /inventory/items/{egg_item_id}` | – (`kind: eggs`, `is_system_managed: true`) |
| 7 | Receive donated eggs (stock-in, no item setup) | `POST /inventory/stock-in` (`output: eggs`) | `movement_egg_in_id` |
| 8 | Receive eggs from other sources (stock-in reasons) | `POST /inventory/stock-in` (`reason: received`; examples `purchase`, `other`, `production` → 422) | `movement_egg_other_id` |
| 9 | Start incubation taking eggs from stock | `POST /breeding-projects` (`consume_egg_stock`) | `breeding_project_egg_id` |
| 10 | Give away eggs (stock-out, no sale) | `POST /inventory/stock-out` (`output: eggs`) | `movement_egg_out_id` |
| 11 | Write off or use eggs (stock-out reasons) | `POST /inventory/stock-out` (`reason: damaged`; examples `spoiled`, `internal_use`, `lost`, `sale` → 422, too many → 409) | `movement_egg_damaged_id` |
| 12 | Egg and milk available balances (read-only) | `GET /inventory/output-balances` | – |
| 13 | Egg movement history | `GET /inventory/movements?inventory_item_id=` | – |
| 14 | Cancel incubation returning eggs to stock | `POST /breeding-projects/{id}/cancel` | – |
| 15 | Record a sale of eggs (by output) | `POST /sales` (`items[].output: eggs`; examples `409 insufficient_stock`, `422` several stores) | `sale_egg_id` |
| 16 | Record a purchase of eggs (by output) | `POST /purchases` (`items[].output: eggs`; examples `422` both/neither/no store) | `purchase_egg_id` |
| 17 | Receive milk (stock-in, no item setup) | `POST /inventory/stock-in` (`output: milk`, `opening_balance`; examples `donation`, `purchase`, `production` → 422) | `movement_milk_in_id` |
| 18 | Write off or use milk (stock-out reasons) | `POST /inventory/stock-out` (`reason: spoiled`; examples `internal_use`, `donation`, `lost`, `sale` → 422) | `movement_milk_out_id` |
| 19 | Record a sale of milk (by output) | `POST /sales` (`items[].output: milk`; example `409 insufficient_stock`) | `sale_milk_id` |
| 20 | Donate feed (stock-out) | `POST /inventory/stock-out` | – |
| 21 | Receive feed from other sources (stock-in reasons) | `POST /inventory/stock-in` (`reason: aid`; examples `production`, `donation`, `received`) | `movement_feed_aid_id` |
| 22 | Record a sale of surplus feed | `POST /sales` | `sale_feed_id` |

**Data flow**

```text
egg_collection (3 crates + 14 pieces)
   -> record (104 pieces)  +  stock_in +104 (reason production)  [one entry]
donation of 20            -> stock_in +20 (NO egg_collection record)
received 12               -> stock_in +12 (reason received)
incubation of 40          -> breeding project  +  stock_out -40 (reason incubation)  [one entry]
1 crate given away        -> stock_out -30 (reason donation; no sale, no income)
2 cracked                 -> stock_out -2  (reason damaged)
available (request 12)    = 104 + 20 + 12 - 40 - 30 - 2 = 64   (production total stays 104)
cancel, 10 eggs returned  -> stock_in +10 (reason returned, linked to the project)         -> 74
sale of 12 by output      -> sale + stock_out -12 (reason sale)                              -> 62
purchase of 30 by output  -> purchase + stock_in +30 (reason purchase) + expense             -> 92
milk: opening 40 l, spoiled 2 l, sale 5 l (all by output)                                    -> 33 l
```

**Expected state changes**

- One Eggs item and one Milk item exist (created by the first collection / the first milk stock-in or purchase); every movement carries its reason, its source (record / breeding project / sale / purchase / manual) and the cycle where one applies.
- `GET /inventory/output-balances` reports the derived balances; the dashboard / reports still report 104 produced.
- A sale by `output` never creates stock; a purchase by `output` resolves/creates the item (the farm has two stores here, so the examples pass `storage_location_id`; omitting it is the documented `422`).

**Business rules to notice**

- Donated, purchased or received eggs are never an `egg_collection`.
- `sale`, `production_use` and `incubation` are refused on the manual stock-out (422): each event has one authoritative endpoint; `production` is refused for eggs/milk on stock-in (422).
- A stock line names exactly one of `inventory_item_id` / `output`.
- Cancelling an incubation returns eggs only when `eggs_returned_to_stock` says so.

**What the frontend should learn**

- Build the In/Out screens from `reasons.by_item_kind` (not the deprecated flat lists); call each entry's `route.url`.
- Show "available" from `output-balances` and "produced" from records/reports - they are different numbers.
- Egg and milk screens need no item id: send `output`.
- Refetch the balance, movements and the breeding project after each step.

**Execution.** Executed with Newman against a live local server on a fresh MySQL database, together with every other flow, in the order 1, 2, S, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 13: 170 requests, 361 assertions, 0 failures (Flow 11 needs a queue worker and Flow 12 needs `notifications:generate`, both run by the harness).

## Flow 15 — Marketplace listings (Phase 23)

**Goal.** A marketplace-only seller (no farm) opens a shop, gets it approved, creates listings with decimal-safe prices and a seller-declared package, uploads a photo, publishes (immediately, no per-listing approval), is found by anonymous buyers, pauses, is restricted and un-restricted by a platform admin and publishes again.

**Prerequisites.** A platform admin account (`admin_email`, `admin_password`; `php artisan platform:grant-admin`). No farm, no other flow. Manual input: the emailed OTP in `otp_code` before request 3. Request 16 uploads `docs/postman/samples/listing-photo.png` (a generated picture), so run with `newman --working-dir docs/postman` or pick the file in Postman. The session switches between the seller and the admin with log-out / log-in requests.

**Request sequence**

| # | Request | Call |
|---|---|---|
| 1 | Initialise CSRF cookie | `GET /auth/csrf-cookie` |
| 2 | Register a marketplace seller (no farm) | `POST /auth/register` |
| 3 | Verify email with OTP | `POST /auth/email/verify` |
| 4 | Create a shop | `POST /marketplace/shops` |
| 5 | Set private contact | `PATCH /marketplace/shops/{{shop_id}}/contact` |
| 6 | Submit the shop for review | `POST /marketplace/shops/{{shop_id}}/submit` |
| 7 | Log out the seller | `POST /auth/logout` |
| 8 | Log in as the platform admin | `POST /auth/login` |
| 9 | Approve the shop | `POST /platform-admin/marketplace/shops/{{shop_id}}/approve` |
| 10 | Log out the admin | `POST /auth/logout` |
| 11 | Log in as the seller | `POST /auth/login` |
| 12 | Product options | `GET /marketplace/product-options` |
| 13 | Image library (empty until assets are seeded) | `GET /marketplace/image-library` |
| 14 | Create a listing - chicken, N8,000 per head | `POST /marketplace/shops/{{shop_id}}/listings` |
| 15 | Create a listing - eggs per crate (seller-declared package) | `POST /marketplace/shops/{{shop_id}}/listings` |
| 16 | Price preview | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/price-preview` |
| 17 | Eligible inventory (a marketplace-only shop has none) | `GET /marketplace/shops/{{shop_id}}/listings/eligible-inventory` |
| 18 | Upload a photo | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/images` |
| 19 | Move / describe the photo | `PATCH /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/images/{{listing_image_id}}` |
| 20 | Fetch the photo as a member | `GET /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/images/{{listing_image_id}}/file` |
| 21 | Update the price (with version) | `PATCH /marketplace/shops/{{shop_id}}/listings/{{listing_id}}` |
| 22 | Update with a stale version (409) | `PATCH /marketplace/shops/{{shop_id}}/listings/{{listing_id}}` |
| 23 | Publish the chicken listing | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/publish` |
| 24 | Publish the image-less eggs listing | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_b_id}}/publish` |
| 25 | Public feed | `GET /public/marketplace/listings` |
| 26 | Public listing detail | `GET /public/marketplace/listings/{{listing_slug}}` |
| 27 | Public price estimate | `GET /public/marketplace/listings/{{listing_slug}}/price-preview` |
| 28 | Public catalogue image (404 until assets are seeded) | `GET /public/marketplace/catalogue-images/chicken` |
| 29 | Public photo file | `GET /public/marketplace/images/{{listing_image_id}}` |
| 30 | Pause the chicken listing | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/pause` |
| 31 | Public detail after pause (404) | `GET /public/marketplace/listings/{{listing_slug}}` |
| 32 | Publish again | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/publish` |
| 33 | Log out the seller | `POST /auth/logout` |
| 34 | Log in as the platform admin | `POST /auth/login` |
| 35 | Admin: list listings | `GET /platform-admin/marketplace/listings` |
| 36 | Admin: restrict the listing | `POST /platform-admin/marketplace/listings/{{listing_id}}/restrict` |
| 37 | Public detail after restriction (404) | `GET /public/marketplace/listings/{{listing_slug}}` |
| 38 | Admin: show the listing and its history | `GET /platform-admin/marketplace/listings/{{listing_id}}` |
| 39 | Admin: lift the restriction | `POST /platform-admin/marketplace/listings/{{listing_id}}/lift-restriction` |
| 40 | Log out the admin | `POST /auth/logout` |
| 41 | Log in as the seller | `POST /auth/login` |
| 42 | Reload the listing (the platform moved its version) | `GET /marketplace/shops/{{shop_id}}/listings/{{listing_id}}` |
| 43 | Publish after the restriction is lifted | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/publish` |
| 44 | List my shop's listings | `GET /marketplace/shops/{{shop_id}}/listings` |
| 45 | Delete the photo | `DELETE /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/images/{{listing_image_id}}` |
| 46 | Archive the eggs listing | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_b_id}}/archive` |
| 47 | Restore it to a draft | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_b_id}}/restore` |
| 48 | Delete the draft | `DELETE /marketplace/shops/{{shop_id}}/listings/{{listing_b_id}}` |

**Business rules shown.** Publishing is immediate for an `active` shop and needs no image (the image-less eggs listing shows `image.source = placeholder`); the package of a crate is a seller statement (`is_conversion: false`); `version` protects field edits (a stale version → `409 stale_listing`) and photos do not move it; pausing, restricting or archiving makes the public address answer `404`; lifting a restriction leaves the listing `paused` and the seller publishes again; the public payload never contains `farm_id`, `version`, inventory or contact values.

## Flow 16 — Marketplace negotiation (Phase 24)

**Goal.** Prove the controlled-negotiation rules end to end with four real accounts: a buyer makes offers, the seller accepts one and rejects three, the offer limit and the fixed-price rule hold, staff and non-members cannot respond, and **no private contact detail ever appears in a negotiation response**.

**Prerequisites.** A platform admin account (`admin_email`, `admin_password`; `php artisan platform:grant-admin`). No farm, no other flow. Manual input: the emailed OTP in `otp_code` before each *Verify … email* request (four of them: staff, manager, buyer, seller). The four emails are `staff_email`, `manager_email`, `buyer_email`, `neg_seller_email`: use a **fresh database** or change them before a second run. Registration is limited to 10 per hour per IP and login to 5 per minute per email, which is why the flow spreads the seller's responses over the owner and a manager.

**Request sequence**

| # | Request | Call | Status |
|---|---|---|---|
| 1 | Initialise CSRF cookie | `GET /auth/csrf-cookie` | 204 |
| 2 | Register the staff member | `POST /auth/register` | 201 |
| 3 | Verify the staff member's email with OTP | `POST /auth/email/verify` | 200 |
| 4 | Log out the staff member | `POST /auth/logout` | 200 |
| 5 | Register the manager | `POST /auth/register` | 201 |
| 6 | Verify the manager's email with OTP | `POST /auth/email/verify` | 200 |
| 7 | Log out the manager | `POST /auth/logout` | 200 |
| 8 | Register the buyer | `POST /auth/register` | 201 |
| 9 | Verify the buyer's email with OTP | `POST /auth/email/verify` | 200 |
| 10 | Log out the buyer | `POST /auth/logout` | 200 |
| 11 | Register the seller | `POST /auth/register` | 201 |
| 12 | Verify the seller's email with OTP | `POST /auth/email/verify` | 200 |
| 13 | Create the shop | `POST /marketplace/shops` | 201 |
| 14 | Set private contact (must never appear in any negotiation response) | `PATCH /marketplace/shops/{{shop_id}}/contact` | 200 |
| 15 | Submit the shop for review | `POST /marketplace/shops/{{shop_id}}/submit` | 200 |
| 16 | Log out the seller | `POST /auth/logout` | 200 |
| 17 | Log in as the platform admin | `POST /auth/login` | 200 |
| 18 | Approve the shop | `POST /platform-admin/marketplace/shops/{{shop_id}}/approve` | 200 |
| 19 | Log out the platform admin | `POST /auth/logout` | 200 |
| 20 | Log in as the seller | `POST /auth/login` | 200 |
| 21 | Product options | `GET /marketplace/product-options` | 200 |
| 22 | Create listing A - negotiable, N8,000 per head | `POST /marketplace/shops/{{shop_id}}/listings` | 201 |
| 23 | Create listing B - fixed price (not negotiable) | `POST /marketplace/shops/{{shop_id}}/listings` | 201 |
| 24 | Create listing C - negotiable (used for the offer limit) | `POST /marketplace/shops/{{shop_id}}/listings` | 201 |
| 25 | Publish listing A | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/publish` | 200 |
| 26 | Publish listing B | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_b_id}}/publish` | 200 |
| 27 | Publish listing C | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_c_id}}/publish` | 200 |
| 28 | Add the staff member (role staff: offer.view only) | `POST /marketplace/shops/{{shop_id}}/members` | 201 |
| 29 | Add the manager (role manager: may respond to offers) | `POST /marketplace/shops/{{shop_id}}/members` | 201 |
| 30 | Log out the seller | `POST /auth/logout` | 200 |
| 31 | Log in as the buyer | `POST /auth/login` | 200 |
| 32 | Fixed-price listing: my negotiation status | `GET /marketplace/listings/{{listing_b_slug}}/offer-status` | 200 |
| 33 | Fixed-price listing rejects negotiation | `POST /marketplace/listings/{{listing_b_slug}}/offers` | 409 |
| 34 | Offer below the 70% floor is refused (no attempt used) | `POST /marketplace/listings/{{listing_slug}}/offers` | 422 |
| 35 | Listing A: make an offer, 10 head at N7,000 | `POST /marketplace/listings/{{listing_slug}}/offers` | 201 |
| 36 | Listing A: a second offer while one is pending is refused | `POST /marketplace/listings/{{listing_slug}}/offers` | 409 |
| 37 | Listing A: my negotiation status | `GET /marketplace/listings/{{listing_slug}}/offer-status` | 200 |
| 38 | Listing C: offer 1 of 3 | `POST /marketplace/listings/{{listing_c_slug}}/offers` | 201 |
| 39 | A buyer cannot respond to a shop's offers (not a member) | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_id}}/accept` | 404 |
| 40 | A buyer cannot list a shop's offers | `GET /marketplace/shops/{{shop_id}}/offers` | 404 |
| 41 | Log out the buyer | `POST /auth/logout` | 200 |
| 42 | Log in as the staff member | `POST /auth/login` | 200 |
| 43 | Staff: list pending offers (offer.view) | `GET /marketplace/shops/{{shop_id}}/offers?status=pending` | 200 |
| 44 | Staff cannot accept (needs offer.respond) | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_id}}/accept` | 403 |
| 45 | Staff cannot reject (needs offer.respond) | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_c_id}}/reject` | 403 |
| 46 | Log out the staff member | `POST /auth/logout` | 200 |
| 47 | Log in as the seller | `POST /auth/login` | 200 |
| 48 | Shop: show offer A | `GET /marketplace/shops/{{shop_id}}/offers/{{offer_id}}` | 200 |
| 49 | Shop: accept offer A | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_id}}/accept` | 200 |
| 50 | Shop: accepting again is idempotent | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_id}}/accept` | 200 |
| 51 | Shop: rejecting an accepted offer is refused | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_id}}/reject` | 409 |
| 52 | Shop: reject offer 1 on listing C | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_c_id}}/reject` | 200 |
| 53 | Log out the seller | `POST /auth/logout` | 200 |
| 54 | Log in as the buyer | `POST /auth/login` | 200 |
| 55 | Show my accepted offer | `GET /marketplace/my/offers/{{offer_id}}` | 200 |
| 56 | Listing A: no further offer after acceptance | `POST /marketplace/listings/{{listing_slug}}/offers` | 409 |
| 57 | Proceed at the listed price on the fixed-price listing B | `POST /marketplace/listings/{{listing_b_slug}}/purchase-intent` | 201 |
| 58 | Repeating the purchase intent is idempotent | `POST /marketplace/listings/{{listing_b_slug}}/purchase-intent` | 200 |
| 59 | Listing C: offer 2 of 3 | `POST /marketplace/listings/{{listing_c_slug}}/offers` | 201 |
| 60 | Log out the buyer | `POST /auth/logout` | 200 |
| 61 | Log in as the manager | `POST /auth/login` | 200 |
| 62 | Manager: reject offer 2 on listing C (manager may respond) | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_c_id}}/reject` | 200 |
| 63 | Log out the manager | `POST /auth/logout` | 200 |
| 64 | Log in as the buyer | `POST /auth/login` | 200 |
| 65 | Listing C: offer 3 of 3 | `POST /marketplace/listings/{{listing_c_slug}}/offers` | 201 |
| 66 | Log out the buyer | `POST /auth/logout` | 200 |
| 67 | Log in as the manager | `POST /auth/login` | 200 |
| 68 | Manager: reject offer 3 on listing C | `POST /marketplace/shops/{{shop_id}}/offers/{{offer_c_id}}/reject` | 200 |
| 69 | Log out the manager | `POST /auth/logout` | 200 |
| 70 | Log in as the buyer | `POST /auth/login` | 200 |
| 71 | Listing C: the fourth offer exceeds the limit | `POST /marketplace/listings/{{listing_c_slug}}/offers` | 409 |
| 72 | Listing C: status shows no attempts left | `GET /marketplace/listings/{{listing_c_slug}}/offer-status` | 200 |
| 73 | Listing C: proceed at the listed price still works | `POST /marketplace/listings/{{listing_c_slug}}/purchase-intent` | 201 |
| 74 | My enquiries (offers + purchase intents) | `GET /marketplace/my/enquiries?per_page=50` | 200 |
| 75 | Log out the buyer | `POST /auth/logout` | 200 |
| 76 | Log in as the seller | `POST /auth/login` | 200 |
| 77 | Shop: list purchase intents | `GET /marketplace/shops/{{shop_id}}/purchase-intents` | 200 |
| 78 | Shop: list all offers | `GET /marketplace/shops/{{shop_id}}/offers` | 200 |
| 79 | Log out the seller | `POST /auth/logout` | 200 |

**Chain of effects**

```
4 accounts -> shop (private contact set) -> admin approves -> listings A (negotiable), B (fixed price), C (negotiable) published
   -> buyer: B refuses negotiation | 5000 refused (floor 5600) | A offer 7000 pending | 2nd offer refused (offer_pending) | C offer 1
   -> buyer / staff cannot respond (404 / 403) -> owner accepts A (accepted in principle) and rejects C offer 1
   -> buyer: A closed (offer_already_accepted) | purchase intent on B (201, then 200) | C offer 2 -> manager rejects | C offer 3 -> manager rejects
   -> 4th offer on C refused (offer_limit_reached) | purchase intent on C still works | seller lists 4 offers + 2 intents
```

**Assertions worth knowing.** Every buyer- and seller-facing response is checked to contain none of the shop's phone, e-mail or address (`+2348031234567`, `ada.private@example.com`, `12 Secret Street`); `contact` is `null` even on the accepted offer; sellers see the buyer's display name only; the 70% floor is computed on the unit price (`5000` refused, `7000` accepted against `8000`); the refused 4th offer reports `details.max_attempts = 3`. A non-member gets `404 not_found` (the shop's offers are not disclosed), shop staff get `403 forbidden` (they hold `offer.view` but not `offer.respond`), a manager may respond.

**What it does NOT create.** No inventory movement, stock deduction or reservation, sale, invoice, payment or finance transaction: after the run only the marketplace tables, `audit_logs` and the identity/shop tables contain rows (checked on the database; enforced permanently by `MarketplaceOfferIsolationTest`).

**Execution.** Executed with Newman against `php artisan serve` on the isolated test database (`farm_management_test`: fresh `migrate:fresh --seed`, platform admin granted): **79 requests, 197 assertions, 0 failures** (Newman counts 83 requests because the harness used four helper calls to read the emailed OTP from `storage/logs/laravel.log`). No saved response examples were captured for this flow; folder 25 holds the reference requests.

## Flow 17 — Marketplace deals (Phase 25)

**Goal.** Prove the deal rules end to end with five real accounts: the buyer confirms an accepted offer (door A) or the seller confirms a purchase request and then the buyer confirms the exact terms (door B); terms are frozen and the unknown delivery charge is never invented; contact is released only through the audited contact endpoints (the private address only for a pickup deal); completion is two-sided and self-reported; cancellation ends contact; a report is accepted in any state, never changes the deal and is invisible to the reported party; the platform admin reviews read-only.

**Prerequisites.** A platform admin account (`admin_email`, `admin_password`; `php artisan platform:grant-admin`). No farm, no other flow. Manual input: the emailed OTP in `otp_code` before each *Verify … email* request (five of them). The five emails are `deal_staff_email`, `deal_manager_email`, `deal_buyer_email`, `deal_buyer2_email`, `deal_seller_email`: use a **fresh database** or change them before a second run. Registration is limited to 10 per hour per IP and login to 5 per minute per email, which is why the flow spreads the seller's responses over the owner and a manager.

**Request sequence**

| # | Request | Call | Status |
|---|---|---|---|
| 1 | Initialise CSRF cookie | `GET /auth/csrf-cookie` | 204 |
| 2 | Register the staff member | `POST /auth/register` | 201 |
| 3 | Verify the staff member's email with OTP | `POST /auth/email/verify` | 200 |
| 4 | Log out the staff member | `POST /auth/logout` | 200 |
| 5 | Register the manager | `POST /auth/register` | 201 |
| 6 | Verify the manager's email with OTP | `POST /auth/email/verify` | 200 |
| 7 | Log out the manager | `POST /auth/logout` | 200 |
| 8 | Register the buyer (negotiated offer) | `POST /auth/register` | 201 |
| 9 | Verify the buyer (negotiated offer)'s email with OTP | `POST /auth/email/verify` | 200 |
| 10 | Log out the buyer (negotiated offer) | `POST /auth/logout` | 200 |
| 11 | Register the buyer (fixed price) | `POST /auth/register` | 201 |
| 12 | Verify the buyer (fixed price)'s email with OTP | `POST /auth/email/verify` | 200 |
| 13 | Log out the buyer (fixed price) | `POST /auth/logout` | 200 |
| 14 | Register the seller | `POST /auth/register` | 201 |
| 15 | Verify the seller's email with OTP | `POST /auth/email/verify` | 200 |
| 16 | Create the shop | `POST /marketplace/shops` | 201 |
| 17 | Set private contact (must only ever appear on the contact endpoints) | `PATCH /marketplace/shops/{{shop_id}}/contact` | 200 |
| 18 | Submit the shop for review | `POST /marketplace/shops/{{shop_id}}/submit` | 200 |
| 19 | Log out the seller | `POST /auth/logout` | 200 |
| 20 | Log in as the platform admin | `POST /auth/login` | 200 |
| 21 | Approve the shop | `POST /platform-admin/marketplace/shops/{{shop_id}}/approve` | 200 |
| 22 | Log out the platform admin | `POST /auth/logout` | 200 |
| 23 | Log in as the seller | `POST /auth/login` | 200 |
| 24 | Product options | `GET /marketplace/product-options` | 200 |
| 25 | Create the listing: negotiable, pickup OR seller delivery, delivery charge agreed separately | `POST /marketplace/shops/{{shop_id}}/listings` | 201 |
| 26 | Publish the listing | `POST /marketplace/shops/{{shop_id}}/listings/{{listing_id}}/publish` | 200 |
| 27 | Add the staff member (deal.view only) | `POST /marketplace/shops/{{shop_id}}/members` | 201 |
| 28 | Add the manager (deal.respond) | `POST /marketplace/shops/{{shop_id}}/members` | 201 |
| 29 | Log out the seller | `POST /auth/logout` | 200 |
| 30 | Log in as the buyer (negotiated offer) | `POST /auth/login` | 200 |
| 31 | Buyer: make an offer, 10 head at N7,000 | `POST /marketplace/listings/{{listing_slug}}/offers` | 201 |
| 32 | Buyer: a pending offer cannot become a deal | `POST /marketplace/my/offers/{{deal_offer_id}}/deal` | 409 |
| 33 | Buyer: no deal exists yet | `GET /marketplace/my/deals` | 200 |
| 34 | Log out the buyer (negotiated offer) | `POST /auth/logout` | 200 |
| 35 | Log in as the manager | `POST /auth/login` | 200 |
| 36 | Manager: accept the offer (agreement in principle only) | `POST /marketplace/shops/{{shop_id}}/offers/{{deal_offer_id}}/accept` | 200 |
| 37 | Manager: a seller cannot create the deal for the buyer | `POST /marketplace/my/offers/{{deal_offer_id}}/deal` | 404 |
| 38 | Log out the manager | `POST /auth/logout` | 200 |
| 39 | Log in as the buyer (negotiated offer) | `POST /auth/login` | 200 |
| 40 | Buyer: the accepted offer shows the confirmation window | `GET /marketplace/my/offers/{{deal_offer_id}}` | 200 |
| 41 | Buyer: the listing offers both methods, so one must be chosen | `POST /marketplace/my/offers/{{deal_offer_id}}/deal` | 422 |
| 42 | Buyer: an invalid phone is refused | `POST /marketplace/my/offers/{{deal_offer_id}}/deal` | 422 |
| 43 | Buyer: confirm the deal (seller delivery, optional phone) | `POST /marketplace/my/offers/{{deal_offer_id}}/deal` | 201 |
| 44 | Buyer: confirming again returns the same deal (idempotent) | `POST /marketplace/my/offers/{{deal_offer_id}}/deal` | 200 |
| 45 | Buyer: the seller's contact (delivery deal: channels and coverage, never the private address) | `GET /marketplace/my/deals/{{deal_id}}/contact` | 200 |
| 46 | Buyer: report completion (self-reported; the deal stays open) | `POST /marketplace/my/deals/{{deal_id}}/complete` | 200 |
| 47 | Log out the buyer (negotiated offer) | `POST /auth/logout` | 200 |
| 48 | Log in as the buyer (fixed price) | `POST /auth/login` | 200 |
| 49 | Buyer 2: proceed at the listed price | `POST /marketplace/listings/{{listing_slug}}/purchase-intent` | 201 |
| 50 | Buyer 2: a stranger cannot read another buyer's deal | `GET /marketplace/my/deals/{{deal_id}}` | 404 |
| 51 | Buyer 2: nor its contact | `GET /marketplace/my/deals/{{deal_id}}/contact` | 404 |
| 52 | Log out the buyer (fixed price) | `POST /auth/logout` | 200 |
| 53 | Log in as the staff member | `POST /auth/login` | 200 |
| 54 | Staff: list deals (deal.view) - no action flags | `GET /marketplace/shops/{{shop_id}}/deals` | 200 |
| 55 | Staff cannot confirm a purchase request (needs deal.respond) | `POST /marketplace/shops/{{shop_id}}/purchase-intents/{{intent_id}}/confirm` | 403 |
| 56 | Staff cannot complete a deal | `POST /marketplace/shops/{{shop_id}}/deals/{{deal_id}}/complete` | 403 |
| 57 | Staff cannot read the buyer's contact | `GET /marketplace/shops/{{shop_id}}/deals/{{deal_id}}/contact` | 403 |
| 58 | Log out the staff member | `POST /auth/logout` | 200 |
| 59 | Log in as the manager | `POST /auth/login` | 200 |
| 60 | Manager: confirm the purchase request (pickup) - NOT an agreement, no contact | `POST /marketplace/shops/{{shop_id}}/purchase-intents/{{intent_id}}/confirm` | 201 |
| 61 | Manager: the same confirmation again returns the open one | `POST /marketplace/shops/{{shop_id}}/purchase-intents/{{intent_id}}/confirm` | 200 |
| 62 | Manager: different terms need a withdrawal first | `POST /marketplace/shops/{{shop_id}}/purchase-intents/{{intent_id}}/confirm` | 409 |
| 63 | Manager: still only one deal exists (the confirmation is not a deal) | `GET /marketplace/shops/{{shop_id}}/deals` | 200 |
| 64 | Manager: the buyer's contact (deal A: name, email, per-deal phone) | `GET /marketplace/shops/{{shop_id}}/deals/{{deal_id}}/contact` | 200 |
| 65 | Manager: complete the deal (second self-report) | `POST /marketplace/shops/{{shop_id}}/deals/{{deal_id}}/complete` | 200 |
| 66 | Manager: a completed deal cannot be cancelled | `POST /marketplace/shops/{{shop_id}}/deals/{{deal_id}}/cancel` | 409 |
| 67 | Log out the manager | `POST /auth/logout` | 200 |
| 68 | Log in as the buyer (fixed price) | `POST /auth/login` | 200 |
| 69 | Buyer 2: no deal and no contact before confirming | `GET /marketplace/my/deals` | 200 |
| 70 | Buyer 2: the seller's confirmation shows the exact terms | `GET /marketplace/my/deal-confirmations/{{confirmation_id}}` | 200 |
| 71 | Buyer 2: the terms must be accepted explicitly | `POST /marketplace/my/deal-confirmations/{{confirmation_id}}/confirm` | 422 |
| 72 | Buyer 2: confirm the seller's terms - the deal exists now | `POST /marketplace/my/deal-confirmations/{{confirmation_id}}/confirm` | 201 |
| 73 | Buyer 2: confirming again returns the same deal (idempotent) | `POST /marketplace/my/deal-confirmations/{{confirmation_id}}/confirm` | 200 |
| 74 | Buyer 2: the seller's contact (pickup deal: the address is disclosed) | `GET /marketplace/my/deals/{{deal_b_id}}/contact` | 200 |
| 75 | Buyer 2: cancel the deal with a reason | `POST /marketplace/my/deals/{{deal_b_id}}/cancel` | 200 |
| 76 | Buyer 2: contact access ended with the cancellation | `GET /marketplace/my/deals/{{deal_b_id}}/contact` | 409 |
| 77 | Buyer 2: report the seller after cancelling (any state) | `POST /marketplace/my/deals/{{deal_b_id}}/report` | 201 |
| 78 | Buyer 2: reporting again returns the existing report | `POST /marketplace/my/deals/{{deal_b_id}}/report` | 200 |
| 79 | Buyer 2: the deal keeps its history and own report | `GET /marketplace/my/deals/{{deal_b_id}}` | 200 |
| 80 | Log out the buyer (fixed price) | `POST /auth/logout` | 200 |
| 81 | Log in as the seller | `POST /auth/login` | 200 |
| 82 | Seller: list the shop's deals | `GET /marketplace/shops/{{shop_id}}/deals` | 200 |
| 83 | Seller: the report is invisible to the reported party | `GET /marketplace/shops/{{shop_id}}/deals/{{deal_b_id}}` | 200 |
| 84 | Seller: a cancelled deal's contact is unavailable | `GET /marketplace/shops/{{shop_id}}/deals/{{deal_b_id}}/contact` | 409 |
| 85 | Log out the seller | `POST /auth/logout` | 200 |
| 86 | Log in as the platform admin | `POST /auth/login` | 200 |
| 87 | Platform admin: deals with reports | `GET /platform-admin/marketplace/deals?reported=true` | 200 |
| 88 | Platform admin: the deal with its report and full history | `GET /platform-admin/marketplace/deals/{{deal_b_id}}` | 200 |
| 89 | Platform admin: the deal is read-only | `POST /platform-admin/marketplace/deals/{{deal_b_id}}` | 405 |
| 90 | Platform admin: both parties' contact (separately audited) | `GET /platform-admin/marketplace/deals/{{deal_b_id}}/contact` | 200 |
| 91 | Platform admin: list deal reports | `GET /platform-admin/marketplace/deal-reports` | 200 |
| 92 | Log out the platform admin | `POST /auth/logout` | 200 |

**Chain of effects**

```
5 accounts -> shop (private contact set) -> admin approves -> negotiable listing (pickup OR delivery, charge agreed separately) published
   DOOR A: buyer offers 10 @ 7000 -> confirming a pending offer refused (offer_not_accepted) -> manager accepts (agreement in principle, no deal, no contact)
           -> buyer confirms: method required (422) | bad phone (422) | seller_delivery + phone -> DEAL 1 (70000.00, charge "To be agreed directly") -> repeat = same deal
           -> buyer reads the seller's contact (no private address for delivery) -> buyer self-reports completion (deal stays open)
   DOOR B: buyer 2 proceeds at the listed price (12 @ 8000) -> stranger refused (404) -> staff refused (403) ->
           manager confirms (awaiting_buyer, NO deal, NO contact; repeat = same; different terms = confirmation_pending)
           -> manager reads the buyer's contact on deal 1, completes it (both sides -> completed) and cannot cancel it afterwards
           -> buyer 2: accept_terms required -> confirms -> DEAL 2 (96000.00, pickup) -> contact incl. pickup address -> cancels -> contact ends (409)
           -> reports the seller after cancelling (deal stays cancelled) -> seller sees no trace of the report
   ADMIN:  reported deals (1) -> deal with its report and history -> POST refused (405) -> both parties' contact (audited) -> deal reports (1)
```

**Assertions worth knowing.** Every non-contact response is checked to contain none of the shop's phone, e-mail or address (`+2348031234567`, `ada.private@example.com`, `12 Secret Street`) nor the buyers' phone or e-mails; `contact` is `null` on every deal and confirmation; `terms.product_total` is `70000.00` / `96000.00` and `delivery_charge.amount` is `null` with `display: "To be agreed directly"`; `completion.verification` is always `self_reported`; a stranger gets `404`, staff `403`; the delivery deal's contact has no address and the pickup deal's has `12 Secret Street, Bodija`; the reported party's view has no `reported` event and no `misrepresented_product`.

**What it does NOT create.** No inventory movement, stock deduction or reservation, sale, invoice, payment or finance transaction (database checked after the run: 0 rows in inventory items/movements, sales, invoices, payments and finance transactions; enforced permanently by `MarketplaceDealIsolationTest`). The 72-hour confirmation window and lapse behaviour need clock changes and are covered by the PHPUnit suites.

**Execution.** Executed with Newman against `php artisan serve` on the isolated test database (`farm_management_test`: fresh `migrate:fresh --seed`, platform admin granted): **92 requests, 226 assertions, 0 failures** (Newman counts 97 requests because the harness used five helper calls to read the emailed OTP from `storage/logs/laravel.log`). Database after the run: `marketplace_deals` 2 (1 completed, 1 cancelled), `marketplace_deal_confirmations` 1, `marketplace_deal_events` 7, `marketplace_deal_reports` 1, `marketplace_deal_contact_views` 4. No saved response examples were captured for this flow; folder 26 holds the reference requests.

## Flow 18 — Marketplace reports & moderation (Phase 27)

**Goal.** A buyer files complaints about a listing and a shop; the platform admin triages them (review, resolve, dismiss) and, only when explicitly chosen, enforces (restrict a listing, suspend a shop) and later reverses the enforcement with a reason.

**Prerequisites.** Flow 17 run on the same database in the same Newman process (it leaves an active shop, one published listing, an accepted offer `deal_offer_id`, `shop_id`, `listing_id`, `listing_slug`). The platform admin account (`admin_email` / `admin_password`). Run Flow 19 after it, not before: Flow 18 leaves the listing `paused` and Flow 19 publishes it again.

| # | Step | Result |
|---|---|---|
| 1-3 | CSRF, log in as the buyer, find `shop_slug` from the public directory | 200 |
| 4-8 | Report the listing (suspected fraud), repeat it, report the shop (spam), report the listing (prohibited content), report the shop (abusive behaviour) | 201, duplicate 200 (same case), 201, 201, 201 |
| 9-10 | List my reports; show one | 4 open; no `reporter_id`, no `history` |
| 11-12 | Log out; log in as the platform admin | 200 |
| 13-17 | Safety summary, offers (read-only), one offer, open reports, one report | admin sees `reporter_id` and `history`; no contact data in offers |
| 18-21 | Report A: `in_review` -> `resolved` (no enforcement); listing stays public; reopening a terminal report | 200, 200, 200, `409 invalid_report_state` |
| 22-23 | Report B: `in_review` -> `dismissed` | 200 |
| 24-28 | Report C: enforcement without an id is refused (422); `in_review`; resolve with `restrict_listing`; the listing is no longer public (404); lift the restriction with a reason (listing -> `paused`) | 422, 200, 200, 404, 200 |
| 29-33 | Report D: `in_review`; resolve with `suspend_shop`; the shop is hidden (404); reinstate with a reason (shop -> `active`); shop public again | 200, 200, 404, 200, 200 |
| 34 | Summary: no open content reports left | 200 |
| 35-38 | Log out; log in as the buyer; the buyer sees the outcome reason of report C; log out | 200 |

**Rules shown.** Reports follow `open -> in_review -> dismissed|resolved` (a report cannot jump straight from `open` to a terminal state: `409 invalid_report_state`); enforcement is never implied by a status, only by the explicit `enforcement_action` + `enforcement_id`; reinstating and lifting need a reason; reporters never see handling detail.

**Execution.** Newman against `php artisan serve` on a disposable MySQL database after Flow 17: **38 requests, 101 assertions, 0 failures**.

## Flow 19 — Marketplace seller plans & promotions (Phase 26, Paystack not exercised)

**Goal.** Configure the monetisation catalogue as a platform admin, switch the two features on, browse them as the seller, attempt both checkouts, prove the webhook rejects an unsigned delivery, inspect the admin lists, switch the features off again.

**Prerequisites.** Flows 17 and 18 (seller shop `shop_id`, `listing_id`, seller login). Platform admin account. **No Paystack key is configured**, so the payment steps stop at the checkout: a checkout answers `503 payments_unavailable` and records nothing (the test also accepts `200/201` with a real test key and `502 gateway_unavailable` when Paystack is unreachable).

| # | Step | Result |
|---|---|---|
| 1-9 | Admin: list seeded plans (Free = 10), create a plan, set 30/365-day prices, activate it with a limit, create and update a promotion package, list packages | 200/201 |
| 10-11 | Admin: enable `marketplace_seller_plans` and `marketplace_promotions` | 200 |
| 12-14 | Log out; log in as the seller; publish the listing again (Flow 18 left it paused) | 200 |
| 15-18 | Seller: plan and allowance, allowance, available plans, promotion packages | 200 |
| 19-20 | Seller: start plan checkout and promotion checkout | 503 `payments_unavailable` (no Paystack key) |
| 21-22 | Seller: payment and promotion history | 200 (empty) |
| 23-24 | Log out; webhook without a valid `x-paystack-signature` | `401 invalid_signature` |
| 25-30 | Admin: log in, service payments, promotions, disable both flags, log out | 200 |

**Not executable without Paystack test-mode credentials** (documented in the reference folder 27, not run): `POST .../payments/{reference}/verify`, a signed `charge.success` webhook, settlement (`benefit_granted=true`), allowance after upgrade, a promoted listing leading discovery with `promotion.label="Sponsored"`, and `POST /platform-admin/marketplace/promotions/{id}/cancel`. Run them once with a test key before launch: set `PAYSTACK_SECRET_KEY`, open the returned `authorization_url`, pay with a Paystack test card, then call verify.

**Execution.** Newman, same database and process as Flows 17-18: **30 requests, 64 assertions, 0 failures**.

Flow 17 request 78 now repeats the same issue category as request 77, matching Phase 27 active-issue deduplication.
