# Health records and medicine — Phase 10

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side; **never send `farm_id`** (rejected 422). Unknown or foreign cycle, item, storage-location, lot and record IDs return `404 not_found`. Responses use the standard envelope `{data, meta, message}`; errors use `{message, code, request_id, errors?, details?}`.

## What a health record is

A health record is a **real farm event that happened** (a vaccination given, a medication administered, a vet visit, a disease observed). It is never created by a task becoming due. It is immutable: there is no `PATCH` or `DELETE`; corrections are a reversal row plus a linked replacement.

- `recorded_at` is when the event happened (ISO-8601 **with** an explicit offset, `cycle start <= recorded_at <= now`); `created_at` is when the system stored it. Both are returned.
- A record never changes livestock population. If animals died, record **mortality** with `POST /records` (Phase 8) and optionally link it with `mortality_record_id`. Health never creates or reverses a mortality record, so population has exactly one source.
- Every medicine line consumes stock through the Phase 9 ledger (one `stock_out`, reason `use`) in the same transaction as the record. Stock is never edited directly.

## Types

`GET /master/health-record-types` returns the executable contract. The vocabulary is closed; unknown `type` or `details` keys are 422.

| `type` | Cycle kinds | Medicines | `details` |
|---|---|---|---|
| `vaccination` | livestock | required (≥1) | `problem_type`, `target_disease` (both optional; text required when `problem_type` = `other`) |
| `medication` | livestock | required | `problem_type`, `condition` (same) |
| `deworming` | livestock | required | `problem_type`, `parasite_target` (same) |
| `treatment` | livestock | required | `problem_type`, `condition` (same) |
| `disease_issue` | livestock | forbidden | `severity` (`low`/`moderate`/`high`) required; `problem_type`, `condition` (same), `symptoms` optional |
| `vet_visit` | livestock | forbidden | `vet_name` required; `problem_type`, `condition` (same), `findings` optional |
| `reversal` | (server) | — | `reason` — created by the reverse endpoint only |

### Problem type (the "what is the problem" dropdown)

Every type accepts `details.problem_type`, one of 13 fixed codes (the list is in code, `App\Enums\HealthProblemType`, and is returned by `GET /master/health-record-types` as `problem_types[] {code, label}`):

`breathing`, `digestive`, `worms_internal_parasites`, `skin_external_parasites`, `nervous`, `leg_foot_movement`, `eye`, `injury_wound`, `reproduction_udder_laying`, `weak_not_eating`, `sudden_death_unknown`, `routine_prevention`, `other`.

| `type` | `question` (form label) | `default_problem_type` | "Disease name (if known)" box (`text_field.key`) |
|---|---|---|---|
| `vaccination` | What is it protecting against? | — | `target_disease` (≤200) |
| `medication` | What is it for? | — | `condition` (≤300) |
| `deworming` | What is it for? | `worms_internal_parasites` | `parasite_target` (≤200) |
| `treatment` | What are you treating? | — | `condition` (≤300) |
| `disease_issue` | What's the problem? | — | `condition` (≤300) |
| `vet_visit` | Reason for the visit? | — | `condition` (≤300) |

Rules:

- `problem_type` is optional **for the API** (see backward compatibility below) but **required in the UI**. A value outside the 13 codes is `422` on `details.problem_type`.
- The text box is always optional **except** when `problem_type` = `other`: then it is required (`422` on `details.<text_field.key>`, message `Describe the problem in "Disease name (if known)" when the problem type is Other.`).
- **Backward compatibility:** when `problem_type` is omitted (or `null`) the request is accepted, the text box is not required, and the stored value is `worms_internal_parasites` for `deworming` and `other` for every other type. Existing clients that send only the text keep working. This is for backward compatibility with older clients only: **new UI must require a selection on every health form and always send `problem_type`** (deworming preselects `worms_internal_parasites`; other types start empty and the form cannot be submitted until one is chosen).
- Stored inside the existing `details` JSON (no schema change). Records written before this field existed are **not** backfilled: the resource returns `problem_type: null`, `problem_type_label: null` for them.
- A reversal row carries the original record's `problem_type` (nothing is added when the original has none). A correction is a normal `POST` and is validated exactly like any other write.
- The response adds top-level `problem_type` and `problem_type_label` (the same value also appears in `details.problem_type`). No existing response field changed.

Common optional top-level fields: `animals_affected` (livestock; whole count ≤ current cycle population), `follow_up_on` (`Y-m-d`, not before the event's farm-local day; **stored only — no task is created until the Work phase**), `mortality_record_id` (livestock; an existing, non-reversed Phase 8 `mortality` record of the same cycle), `notes`.

Health is the **livestock/fish** domain only. Every type is refused on a crop cycle (`422` on `type`); crop pest, fertilizer and irrigation records belong to the operational-record and crop phases, and `fertilizer_agrochemical` items are never health medicines. Medicine lines accept inventory items of category `medicine` only; anything else is `422` on `medicines.N.inventory_item_id`.

## Endpoints and permissions

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/master/health-record-types` | `health.view` | 200 |
| GET | `/health-records` | `health.view` | 200 paginated |
| POST | `/health-records` | `health.create` (+ `health.reverse` when `corrects_record_id` is sent) | 201 |
| GET | `/health-records/{record}` | `health.view` | 200 |
| POST | `/health-records/{record}/reverse` | `health.reverse` | 201 reversal record |
| GET | `/health/withdrawals` | `health.view` | 200 paginated |
| GET | `/master/medicine-options` | `health.view` | 200 |
| POST | `/health/medicines` | `health.manage` + `inventory.manage` (+ `measurement.manage` with `packaging`) | 201 (200 on idempotent replay) |
| GET | `/health/medicines` | `health.view` | 200 paginated |
| GET | `/health/medicines/{item}` | `health.view` | 200 with `balances` and `lots` |
| PUT | `/health/medicines/{item}/profile` | `health.manage` | 200 |

Role presets: **Owner/Manager** all four (`view`, `create`, `reverse`, `manage`); **Farm Worker** `view` + `create` (cannot correct or edit medicine metadata); **Vet** (new role id `vet`, assignable by Owner and Manager) all four health permissions plus read-only farm, cycle, location, measurement, record and inventory access — no `inventory.use` and no `record.create`; **Finance** has no health access (403). Writes share a 120/hour/user limit (429). Not subscription gated.

## Create a health record

`POST /health-records`

```json
{
  "production_cycle_id": "0198…",
  "type": "vaccination",
  "recorded_at": "2026-10-01T08:30:00+01:00",
  "idempotency_key": "a-unique-key-per-real-event",
  "details": {"problem_type": "routine_prevention", "target_disease": "Newcastle disease"},
  "animals_affected": 100,
  "follow_up_on": "2026-10-22",
  "mortality_record_id": null,
  "notes": "Whole flock",
  "medicines": [
    {
      "inventory_item_id": "0198…",
      "storage_location_id": "0198…",
      "lot_id": "0198…",
      "components": [{"quantity": "2", "unit": "bottle"}, {"quantity": "50", "unit": "ml"}],
      "dose_per_animal": [{"quantity": "0.3", "unit": "ml"}],
      "dosage_instructions": "One drop per bird",
      "withdrawal_days": 7
    }
  ]
}
```

Medicine line fields (max 10 lines; the same item + location + lot may appear once):

- `components` — the quantity **taken from stock**, a Phase 5 compound quantity. Its dimension must match the item's stock unit (a `ml` medicine accepts `ml`, `l`, `bottle`…; `kg` → `422 unit_dimension_mismatch`).
- **Packages** (`bottle`, `sachet`, `pack`…) never have a built-in size. They resolve **only** through the item's own conversion (`POST /settings/package-conversions` with `context_type: "inventory_item"`, `context_id: <item id>`); otherwise `422 conversion_not_configured`. The conversion used is snapshotted in `measurement`, so later changes to the package size never rewrite history.
- `dose_per_animal` — optional informational dose (weight, volume or count, packages through the same item context); livestock only. Stored normalised in `dose_per_animal`; it does **not** change the stock deducted.
- `lot_id` — required when the item tracks lots, forbidden otherwise. A lot whose expiry date is before the event's farm-local day is refused `409 lot_expired` (write expired stock off through Phase 9 instead). There is no automatic FEFO; the client chooses the lot.
- `withdrawal_days` — optional override, `0..3650`. When omitted the medicine's `default_withdrawal_days` is used. `0` means "no withdrawal".

The whole request is atomic: any failure (`409 insufficient_stock` for any line — including dated history, `lot_expired`, `item_inactive`, `cycle_closed`; any 422/404) leaves no record, no line and no stock movement.

### Retries

`idempotency_key` (required, ≤80 chars of `A-Za-z0-9._:-`, farm-scoped) names one real-world event. An identical retry returns the original record with `201` and **does not deduct stock again**; the same key with different content is `409 idempotency_conflict`. A failed attempt does not consume the key. Keep one key per event; different keys are different events.

### Response

```json
{"data": {
  "id": "0198…", "production_cycle_id": "0198…", "type": "vaccination",
  "details": {"problem_type": "routine_prevention", "target_disease": "Newcastle disease"},
  "problem_type": "routine_prevention", "problem_type_label": "Routine care or prevention (check-ups, vitamins, general vaccination)",
  "animals_affected": 100, "follow_up_on": "2026-10-22", "mortality_record_id": null,
  "recorded_at": "2026-10-01T07:30:00.000000Z", "notes": "Whole flock",
  "reverses_record_id": null, "corrects_record_id": null, "reversed_by_record_id": null,
  "medicines": [{
    "id": "0198…", "line_no": 1, "inventory_item_id": "0198…", "item_name": "Newcastle vaccine",
    "storage_location_id": "0198…", "inventory_lot_id": "0198…", "lot": {"code": "L-2026-09", "expires_on": "2027-03-31"},
    "quantity_used": {"quantity": "250", "unit": "ml", "normalized": {"quantity": "250", "unit": "ml"}},
    "measurement": {"entered": [], "normalized": {}, "total": {}, "snapshot": {}},
    "dose_per_animal": {"normalized": {"quantity": "0.3", "unit": "ml"}},
    "dosage_instructions": "One drop per bird",
    "withdrawal": {"days": 7, "source": "explicit", "started_at": "2026-10-01T07:30:00.000Z", "ends_at": "2026-10-08T07:30:00.000Z", "is_active": true},
    "inventory_movement_id": "0198…"
  }],
  "created_by": "0198…", "created_at": "2026-10-01T09:12:44.000000Z"
}, "meta": {}, "message": "Health record saved."}
```

## Withdrawal

Each medicine line stores its effective `withdrawal.days`, where it came from (`source`: `explicit` or `item_default`), and `ends_at = recorded_at + days` (null when there is no withdrawal). Because this is stored on the line, a withdrawal is always traceable to the exact health record, medicine and lot that caused it, and later changes to the medicine's default never alter past records.

`GET /health/withdrawals` lists windows — each row carries `health_record_id`, `health_record_medicine_id`, `production_cycle_id`, `health_record_type`, item, lot, `days`, `source`, `started_at`, `ends_at`, `is_active`. Query: `production_cycle_id`, `inventory_item_id`, `active` (`1` default = windows that have not ended; `0` = also ended ones), `page`, `per_page`. **Reversed records never appear.** There is no farm-wide flag: whether produce is held back is answered by listing active windows.

## Medicines (view over Phase 9 inventory)

Medicines are ordinary Phase 9 inventory items (`category: "medicine"`). Create the item with `POST /inventory/items`, receive stock/lots with `POST /inventory/stock-in`, count with adjustments, and define packaging with package conversions — all in Phase 9. The health endpoints add the withdrawal profile and a medicine-centred read model.

### Medicine catalogue (V1)

`GET /master/medicine-options` (`health.view`) returns `medicine_types` (`antibiotic`, `vaccine`, `supplement`, `dewormer`, `other`), `primary_units` (`mg`, `g`, `kg`, `ml`, `l`, `piece` — counted medicines use `piece`; there are no tablet/dose units), `package_units` (`bottle`, `box`, `card`, `sachet`, `bag`, `vial`), `withdrawal_days` limits and `reasons.in` / `reasons.out` (the permitted stock movement reasons for medicine; see below).

`POST /health/medicines` creates a medicine in one all-or-nothing transaction using the existing services (inventory item → profile → package conversion → stock-in). Body:

```json
{
  "idempotency_key": "create-oxytet-001",
  "name": "Oxytetracycline 20% LA",
  "medicine_type": "antibiotic",
  "stock_unit": "ml",
  "packaging": {"unit": "bottle", "quantity_per_package": "100"},
  "default_withdrawal_days": 14,
  "notes": "Keep below 25 C",
  "tracks_lots": true, "tracks_expiry": true,
  "opening_stock": {
    "storage_location_id": "<uuid>",
    "components": [{"quantity": "5", "unit": "bottle"}],
    "lot": {"code": "OXY-2026-01", "expires_on": "2028-06-30"},
    "recorded_at": "2026-10-01T08:00:00Z",
    "notes": "Opening shelf count"
  }
}
```

- Required: `name`, `medicine_type`, `stock_unit`. Everything else is optional; `packaging` and `opening_stock` can be sent independently, but opening stock in a package unit needs `packaging` (otherwise `422 conversion_not_configured`; nothing is guessed).
- `packaging.quantity_per_package` must be positive (whole when `stock_unit` is `piece`). It creates the per-medicine conversion, so `5 bottle × 100 ml = 500 ml` in the opening stock and in every later health line, stock-in or stock-out.
- `tracks_lots` / `tracks_expiry` default to true when the opening-stock `lot` / `lot.expires_on` is sent, else false; an expiry-tracked medicine needs a lot code and expiry on its first stock. They cannot change after the first movement (`409 item_has_movements`).
- `opening_stock` is written through the inventory ledger as a `stock_in` with reason `opening_balance` (same lot, expiry, future-date and negative-balance rules as `POST /inventory/stock-in`). **No expense, supplier or purchase is created**; use `POST /purchases` for a bought-in lot with its cost.
- Permissions: `health.manage` AND `inventory.manage` (and `measurement.manage` when `packaging` is sent). Owner and Manager hold all three; Vet and Farm worker do not (403) — they keep using the existing endpoints they already could.
- Idempotency: send the optional request-level `idempotency_key` (max 64; letters, digits, `. _ : -`; farm-scoped), with or without opening stock. An identical retry returns the original medicine (`200`, `message: "Medicine already created."`) and creates nothing — no second item, conversion or stock movement. The same key with any changed payload is `409 idempotency_conflict`. The key and a hash of the payload are stored on the medicine profile; a rolled-back attempt stores nothing, so the corrected retry succeeds. When the key is sent, `opening_stock.idempotency_key` is optional and the ledger entry is keyed `medicine-open:<key>`. Without a request key the previous behaviour holds: opening stock requires `opening_stock.idempotency_key` (identical retry → 200, different request under it → `409 idempotency_conflict`), and a repeat of a no-stock create is `409 inventory_item_exists`.
- Response: the full medicine (see below) with `balances` and `lots`.

Every medicine resource (`GET /health/medicines`, `GET /health/medicines/{item}`, profile `PUT`, create) now also carries `medicine_type` (`null` = not specified: every medicine that existed before the type was added keeps `null`; it is never guessed), `medicine_type_label`, and `packaging[]` (active conversions of that medicine: `conversion_id`, `package_unit`, `package_unit_label`, `quantity_per_package`, `unit`; empty when none). `kind` stays `general` for medicines.

**Medicine stock movement reasons.** IN: `opening_balance`, `purchase`, `donation`, `aid`, `received`, `other`. OUT: routine treatment use is **only** a health record (`POST /health-records`, which writes one `use` stock-out per line, linked by `health_record_id`). `POST /inventory/stock-out` now rejects reasons `use` and `internal_use` for medicine items with `422` (field `reason`, message names `POST /health-records`); `donation`, `damaged`, `expired`, `spoiled`, `lost`, `disposal`, `other` stay available, as do adjustments and transfers. This does not alter any existing movement or balance, and old manual `use` rows stay readable.

- `GET /health/medicines` — query: `search`, `medicine_type` (`antibiotic|vaccine|supplement|dewormer|other`; filters on the profile type, untyped medicines are excluded when used, unknown value `422`), `include_inactive`, `low_stock`, `page`, `per_page`. Each row is the inventory item resource (derived `stock`, `is_low_stock`…) plus `profile: {default_withdrawal_days, notes}`.
- `GET /health/medicines/{item}` — adds `balances` (per location and lot) and `lots` (`code`, `expires_on`, `is_expired`, `stock`), soonest expiry first. Items of other categories are 404.
- `PUT /health/medicines/{item}/profile` — body `{"default_withdrawal_days": 7|null, "notes": "…", "medicine_type": "vaccine"}` (`default_withdrawal_days` is required, `null` clears; `medicine_type` is optional: omit = unchanged, `null` = not specified). Applies to future health lines only.

## Reverse and correct

`POST /health-records/{record}/reverse` — `{"reason": "…", "recorded_at": "…", "idempotency_key": "…"}`. `recorded_at` must not precede the original event. This appends a `reversal` record and **one compensating `reversal` stock movement per medicine line** (returning exactly what was consumed, to the same location and lot), and the original's withdrawal windows stop counting. The original rows are not changed; the original shows `reversed_by_record_id`. It cannot be reversed twice and a reversal cannot be reversed (`409 record_already_reversed`). A linked mortality record is not touched — reverse it separately via `POST /records/{id}/reverse` if it was wrong.

To correct: reverse the record, then `POST /health-records` with `corrects_record_id` (same type and cycle, replaces a reversed record exactly once, requires `health.reverse`). Otherwise `409 invalid_correction`. These are two transactions, so a failed replacement leaves the reversal visible.

Stock movements created by health cannot be reversed through `POST /inventory/movements/{id}/reverse` (`409 reverse_via_health_record`). Closed cycles reject new records and reversals (`409 cycle_closed`); reads keep working and an authorised reopen re-enables writes. Inventory movements and their resource now expose `health_record_id` / `health_record_medicine_id`, and `GET /inventory/movements?health_record_id=` filters by it.

## Listing

`GET /health-records` — query: `production_cycle_id`, `type` (including `reversal`), `problem_type` (one of the 13 codes; unknown value `422`), `inventory_item_id` (records using that medicine), `recorded_from`/`recorded_to` (`Y-m-d`, inclusive farm-local days), `page` (≥1), `per_page` (1–100, default 50). Ordered by `recorded_at` desc, then id. A foreign `production_cycle_id`/`inventory_item_id` is 404.

## Errors

`401`, `403 forbidden`, `404 not_found`, `419`, `422 validation_failed` (type/field rules, `medicines`, `animals_affected`, `follow_up_on`, `mortality_record_id`, `recorded_at`, Phase 5 codes `conversion_not_configured` / `unit_dimension_mismatch` / `incompatible_units` / `invalid_quantity`), `409` `cycle_closed`, `insufficient_stock`, `lot_expired`, `item_inactive`, `idempotency_conflict`, `invalid_correction`, `record_already_reversed`, `429`.

## Not in this phase

Crop health/treatment and agrochemical workflows (Phase 13), individual-animal identity, withdrawal-driven blocking of sales/harvest (reporting of active windows only), tasks created from `follow_up_on`, vet contacts/costs/purchasing, health reports, notifications, and a species health capability flag (health follows cycle kind).
