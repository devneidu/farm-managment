# Breeding — Phase 11

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side; **never send `farm_id`** (rejected 422). Unknown or foreign project, outcome and cycle IDs return `404 not_found`. Responses use the standard envelope `{data, meta, message}`; errors use `{message, code, request_id, errors?}`.

## Model

A **breeding project** is one reproductive attempt on one livestock cycle (group-managed; there is no individual animal or pedigree). It keeps three things apart:

| Part | Fields | Nature |
|---|---|---|
| Biological reference | `biological_reference` | Frozen copy of the species capability `reference_config` taken when the project starts. Later master-data edits never change it. |
| Expectation | `expectation`, `expected_offspring` | An **estimate**: derived from the reference or entered manually. Never changes population. |
| Actual | `outcomes`, `result` | What really happened. Only a confirmed live outcome changes population. |

`reference` is a readable code (`BRD-2026-00001`); `id` is the UUID.

## Workflows (capability driven)

| `workflow` | Needs species capabilities | Fields |
|---|---|---|
| `incubation` | `supports_breeding` + `supports_incubation` | `eggs_set` (required) |
| `pregnancy` | `supports_breeding` + `supports_pregnancy` | `females_bred` (optional) |

An unsupported workflow is `422` on `workflow`; `eggs_set` is rejected for pregnancy and `females_bred` for incubation. Crop cycles are refused. Checks accept `fertile_count` for incubation only.

## Expectation rules

`expectation` is an exact date **or** a window, never both and never a midpoint:

| Reference | `expectation` |
|---|---|
| exact (`incubation_days` / `gestation_days`), e.g. chicken 21 days, start 1 Oct | `{type: "exact", source: "reference", date: "2026-10-22"}` |
| range (`_min`/`_max`), e.g. guinea fowl 26–28 days, start 1 Oct | `{type: "window", source: "reference", from: "2026-10-27", to: "2026-10-29"}` |
| range, camel 365–400 days | window start+365 … start+400 |
| cattle (default 283 inside 280–285) | exact start+283; the range stays in `biological_reference` |
| no number (generic snail) | `{type: "none", no_expectation_reason: "no_numeric_reference"}` |
| range with `reference_config.automatic_expectation: false` (honeybee 16–24, depends on caste) | `{type: "none", no_expectation_reason: "qualified_reference"}`; the range and note are still in `biological_reference` |

Only the explicit machine-readable flag `automatic_expectation: false` in the species `reference_config` disables automatic calculation (honeybee, no caste model exists); the human-readable `note` is informational and never changes behaviour. The flag is copied into the snapshot (`biological_reference.automatic_expectation`), so historical projects stay stable. Supply a manual `expected_date` or `expected_from` + `expected_to` for any species; `source` becomes `manual` and the snapshot is untouched. `PATCH` with `start_date` recalculates a reference-derived expectation from the stored snapshot (manual expectations are kept and re-validated); `revert_to_reference: true` drops a manual expectation. After an outcome the project is no longer editable.

`biological_reference`: `{workflow, capability, species_id, species_code, kind: exact|range|none, days, days_min, days_max, approximate, note, automatic_expectation, no_automatic_reason, captured_at}`.

## Lifecycle

`active` → `completed` (an outcome is recorded) · `active` → `cancelled` (abandoned, reason kept). Reversing the only outcome returns `completed` → `active` so a replacement can be recorded. `cancelled` is final. Projects are never deleted. Closed cycles reject every write (`409 cycle_closed`); reads stay available.

## Endpoints and permissions

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/breeding-projects` (`production_cycle_id`, `workflow`, `status`, `page`, `per_page`) | `breeding.view` | 200 paginated |
| POST | `/breeding-projects` | `breeding.create` | 201 |
| GET | `/breeding-projects/{project}` | `breeding.view` | 200 |
| PATCH | `/breeding-projects/{project}` | `breeding.create` | 200 |
| GET | `/breeding-projects/{project}/milestones` | `breeding.view` | 200 |
| POST | `/breeding-projects/{project}/checks` | `breeding.create` | 201 (project) |
| POST | `/breeding-projects/{project}/cancel` | `breeding.create` | 200 |
| POST | `/breeding-projects/{project}/outcomes` | `breeding.create` (+ `breeding.reverse` with `corrects_outcome_id`) | 201 outcome |
| POST | `/breeding-projects/{project}/outcomes/{outcome}/reverse` | `breeding.reverse` | 201 reversal outcome |

Roles: Owner and Manager all three permissions; Farm Worker and Vet `breeding.view` + `breeding.create`; Finance none.

### Start

```json
POST /breeding-projects
{"production_cycle_id":"…","workflow":"incubation","start_date":"2026-10-01","eggs_set":50,
 "expected_offspring":40,"parents":[{"role":"dam","production_cycle_id":"…","head_count":20}],
 "idempotency_key":"start-1"}
```

`start_date` is a farm-local day between the cycle start and today. `parents` (optional) are livestock cycles of the same farm **and species**; each role+cycle once. `idempotency_key` is farm-wide: an identical retry returns the original project, a changed payload is `409 idempotency_conflict`.

### Record an outcome

```json
POST /breeding-projects/{id}/outcomes
{"live_count":37,"loss_count":13,
 "recorded_at":"2026-10-22T09:00:00+01:00","idempotency_key":"hatch-1"}
```

- Eggs set and `expected_offspring` never change population. Live offspring join the project's own cycle automatically (there is no client flag): `live_count > 0` appends one Phase 8 operational record of type `breeding_outcome` (`population_delta = +live_count`) and its population movement in the same transaction. **50 eggs set, 40 expected, 37 hatched → population +37**, once.
- **Eggs from stock (optional).** An incubation project started with `consume_egg_stock: true` (+ optional `egg_storage_location_id`) takes `eggs_set` eggs out of the farm's available egg stock in the same transaction (stock-out reason `incubation`, linked to the project; needs `inventory.use`; `409 insufficient_stock` creates no project). Editing `eggs_set` reconciles the ledger; cancelling returns eggs **only** when `eggs_returned_to_stock` says so. Recording a hatch never changes egg stock. Details: [Feed, eggs and milk stock](FEED-EGGS-MILK-STOCK.md).\n- `live_count: 0` records an unsuccessful attempt and adds nothing. For incubation `live_count + loss_count ≤ eggs_set`.
- `recorded_at` needs an explicit offset and lies between the project start and now; `outcome_date` is its farm-local day, kept separate from the expected date/window and from `created_at`.
- Same `idempotency_key` + same payload returns the original outcome (201) with no second movement; a changed payload is `409 idempotency_conflict`.
- A project holds one effective outcome: a second one is `409 project_not_active`.

### Reverse and correct

`POST …/outcomes/{outcome}/reverse` `{reason, recorded_at, idempotency_key}` appends a `reversal` outcome and, when the original added population, one compensating Phase 8 `reversal` record (−live). If that would make the dated ledger negative (animals died since) it is `409 insufficient_population`. A reversal cannot be reversed again (`409 outcome_already_reversed`). To correct, reverse and then `POST` a new outcome with `corrects_outcome_id` (reversed, replaced once, else `409 invalid_correction`). History is never edited or deleted. The generated Phase 8 record cannot be reversed through `/records/{id}/reverse` (`409 reverse_via_breeding_outcome`).

### Checks and milestones

`POST …/checks` `{checked_on, result: positive|negative|inconclusive, fertile_count?, notes?}` (append-only; pregnancy confirmation or incubation candling). `GET …/milestones` returns a derived timeline: `started`, `expected_outcome` (exact date or window; status `pending|overdue|done|cancelled|not_estimated`), each `check`, and the actual `outcome`. Expected dates are stored information only; reminders and tasks belong to the work/calendar phase.

## Errors

`401`, `403` (permission), `404` (foreign/unknown ids), `409` (`cycle_closed`, `project_not_active`, `idempotency_conflict`, `invalid_correction`, `outcome_already_reversed`, `insufficient_population`), `422` (validation, unsupported workflow, field/workflow mismatch, invalid dates/counts), `429` (`breeding-write` 120/hour/user).
