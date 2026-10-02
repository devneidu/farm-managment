# Crop operations and outputs (Phase 13)

Source: `docs/implementations/24-PHASE-13-CROPS-OUTPUTS.md` ("Crop operations and outputs": land prep, planting, establishment/survival, fertilizer, pesticide/herbicide, irrigation, weeding, growth stage, crop loss, crop harvest, output inventory; "record engine schemas plus crop project detail endpoints").

All paths have prefix `/api/v1`. Same session/CSRF/`X-Farm-Id` rules as the rest of the API; **never send `farm_id`**. Responses use `{data, meta, message}`; errors use `{message, code, request_id, errors?}`.

## What this phase is

Every crop event is a **Phase 8 operational record** posted to the existing `POST /records` endpoint. There is no second event architecture, table or model, and no schema change. A record says what **did** happen; it is never a task (Phase 12) and never creates one. Stock effects use the Phase 9 ledger, quantities use Phase 5 measurements.

| Record type | Status | Purpose | Stock effect |
|---|---|---|---|
| `land_preparation` | **New** | Clearing, ploughing, ridging… (`method`, optional `treated_area`). | none |
| `planting` | **New** | A planting event: `units_planted` (whole planting units), optional `method`, optional seed/material quantity. | optional `stock_out` of `seed_planting_material` |
| `establishment_check` | **New** | `established_units`; the server derives failed units and survival % from the baseline. | none |
| `growth_stage` | **New** | `stage`: germination, seedling, vegetative, flowering, fruiting, maturity, dormant; optional `observation`. | none |
| `crop_loss` | **New** | `units_lost` (whole planting units), `cause`, optional `affected_area`. | none |
| `crop_harvest` | **New** | Harvested produce; quantity, optional `quality`, optional `inventory` target. | optional **`stock_in`** of `produce` |
| `fertilizer_application` | Phase 8, **extended** | See "Inputs". | optional `stock_out` of `fertilizer_agrochemical` |
| `pesticide_application` | **New** | Insecticide, herbicide, fungicide, other. | optional `stock_out` of `fertilizer_agrochemical` |
| `irrigation`, `weeding`, `pest_observation` | Phase 8, unchanged | | none |

`GET /master/record-types` and `GET /record-types/{type}/schema` describe every type, including `inventory_effect_enabled`, `inventory_category`, `inventory_direction` (`in`/`out`), `inventory_required` and `inventory_dimensions`.

## Planting units are the baseline

A crop project has an immutable **baseline** of `initial_planting_units` (for example 50 heaps). Everything below derives from it; none of it is seed quantity, land area or stock.

- `planting.units_planted` is a whole count. The cumulative **non-reversed** planting units cannot exceed the baseline (`422` on `details.units_planted`, with the already-recorded total in the message). Reversing a planting frees that capacity.
- `planting` may also record the **material used** (`components` + `inventory` link to a `seed_planting_material` item, weight or count). The stock moves by that material quantity only, never by the number of heaps.
- `establishment_check.established_units` must be 0 … baseline. The response adds server-generated `baseline_units`, `failed_units = baseline − established` and `survival_percent` (a decimal string, at most 2 places): **50 planted, 47 established → `failed_units: 3`, `survival_percent: "94"`**. 2 of 3 gives `"66.67"`. Submitting `survival_percent` or `failed_units` is rejected. Several assessments may be recorded; the latest non-reversed one is current.
- `crop_loss.units_lost`: cumulative non-reversed losses cannot exceed the baseline. A crop loss has **no population movement** (crops have no population ledger) and `population_delta` is `0`. The livestock `mortality` type is not available on crops.

## Crop project detail

`GET /production-cycles/{cycle}/crop` — requires `production_cycle.view` and `record.view`. A read model, nothing stored; reversed records are excluded.

```json
{"data": {
  "production_cycle_id": "…", "name": "Yam", "reference": "CRP-2026-00001", "status": "active",
  "crop_type": {"id": "…", "code": "yam", "name": "Yam"},
  "production_area": {"id": "…", "name": "North plot"},
  "planting_date": "2026-01-01",
  "baseline": {"planting_unit_type": "heap", "planting_unit_label": "Heap", "initial_planting_units": 50},
  "planting": {"units_planted": 50, "units_remaining_to_plant": 0, "events": 2},
  "establishment": {"record_id": "…", "assessed_at": "…", "established_units": 47, "failed_units": 3, "survival_percent": "94", "assessments": 1},
  "growth_stage": {"record_id": "…", "stage": "flowering", "recorded_at": "…"},
  "losses": {"units_lost": 7, "events": 3, "by_cause": {"Flooding": 5, "Pests": 2}},
  "harvest": {"events": 2, "last_harvest_at": "…", "totals": [{"unit": "g", "quantity": "130000"}]},
  "activity": {"land_preparation": 2, "fertilizer_applications": 1, "pesticide_applications": 0, "irrigation": 0, "weeding": 0, "pest_observations": 0}
}}
```

Harvest totals are per **normalised** unit (`g`, `ml`, `piece`): different dimensions are never added together. `establishment`/`growth_stage` are `null` until recorded. A livestock cycle returns `409 not_a_crop_project`; foreign or unknown projects return `404`.

**Plot scope:** a project sits on one production area (its plot), returned as `production_area`. `GET /records?production_area_id=…` lists the records of every project on that plot (foreign area → `404`); `production_cycle_id` lists one project.

## Harvest and produce stock

`crop_harvest` is a crop **output**. Livestock exits, eggs and milk are never "harvest", and a livestock cycle returns `422` on `type`.

```json
POST /api/v1/records
{"type": "crop_harvest", "production_cycle_id": "…", "recorded_at": "2026-10-02T08:30:00+01:00", "idempotency_key": "harvest-plot-a-1",
 "details": {
   "quality": "Grade A",
   "components": [{"quantity": "12", "unit": "bag"}, {"quantity": "18", "unit": "kg"}],
   "inventory": {"item_id": "…", "storage_location_id": "…", "lot": {"code": "H-OCT", "expires_on": "2026-11-01"}}
 }}
```

- **Inventory integration is optional.** Recording the real-world harvest never depends on produce-inventory setup. Without `details.inventory` the harvest is a normal record: quantity preserved (`measurement.entered`) and normalised (g/ml/piece, Phase 5 rules, must be greater than zero), counted in the project's harvest totals, and **no inventory movement** is created. Reversing it reverses the record only.
- With `details.inventory` the item must be active and in the **`produce`** inventory category (feed, medicine, seed, fertilizer/agrochemical and general supply are rejected `422`). Any weight, volume or count item may receive produce.
- With a link, the item decides the dimension: a kg item is harvested by weight, a litre item by volume, a piece item by count. A unit of another dimension is `422` (the Phase 5 dimension error).
- **One linked harvest = one record = one `stock_in`** movement (`reason: "harvest"`, `operational_record_id` set) in the same transaction. Idempotency works in both modes: retrying the same `idempotency_key` + payload returns the original record with no second movement; a changed payload → `409 idempotency_conflict`. The stock cannot be added or reversed through `/inventory/…` for a harvest-owned movement (`409 reverse_via_record`).
- **Packages/compound quantities** (`12 bag + 18 kg`) on a linked harvest resolve **only** through that item's own package conversion (`/settings/package-conversions`, `context_type=inventory_item`). Without one → `422 conversion_not_configured`; no bag or crate size is ever assumed. `details.context` is rejected beside the link. The record keeps the entered parts (`measurement.entered`) and the normalised quantity (`measurement.normalized`, g/ml/piece).
- **Lots:** lot-tracked items require either `lot_id` (existing lot) or `lot` `{code, expires_on?}` (opens a lot, or joins the existing lot of the same code); both together → `422`; non-lot items reject both. Intake into a lot already expired on the harvest date → `409 lot_expired`.
- The receiving storage location must belong to the farm and be active (`404` otherwise). A foreign item or location is `404`.
- Permission: `record.create`; a linked harvest also needs `inventory.use` (so workers can record a harvest into produce stock).

## Inputs (fertilizer, pesticide/herbicide)

| Field | `fertilizer_application` | `pesticide_application` |
|---|---|---|
| `input_name` ≤200 | required unless `inventory` (defaults to the stocked item's name) | same |
| `method` ≤200 | required | required |
| `product_type` | — | required: `insecticide`, `herbicide`, `fungicide`, `other` |
| `components` | required: quantity applied (weight **or** volume) | same |
| `treated_area` `{quantity, unit}` | optional, area units | optional |
| `concentration` ≤200 | optional free text | optional |
| `target` ≤300, `pre_harvest_interval_days` 0–365 | — | optional |
| `inventory` `{item_id, storage_location_id, lot_id?}` | optional | optional |

The quantity applied, the treated area, the concentration and the stock deducted are four separate things; none is derived from another. With `inventory` the item must be `fertilizer_agrochemical` and weight/volume (count items rejected); one application = one `stock_out` (`reason: "use"`). Packages resolve only through the item's own conversion, lots are required for lot-tracked items, expired lots are `409 lot_expired`, shortage is `409 insufficient_stock`. Unlinked records accept weight or volume and never guess a package size.

## Cycle rules, reversal, correction

- Crop projects only (`422` on `type` for livestock/fish). Closed projects: `409 cycle_closed`, nothing written.
- `recorded_at` needs an explicit offset and must lie between the project start and now.
- `POST /records/{id}/reverse` appends a reversal record and exactly one compensating movement (a harvest reversal removes the produce, an application/planting reversal restores the input). Reversing twice → `409 record_already_reversed`. If produce from a harvest has already been used, its reversal is `409 insufficient_stock` and nothing changes (stock never goes negative).
- To correct, reverse then `POST /records` with `corrects_record_id` (needs `record.reverse`). There is no PATCH/DELETE; history is never rewritten, and reversed records drop out of the project detail figures.

## Not in this phase

Sales, expenses/finance, notifications and reminders, reports, any change to tasks or the calendar, and a fertilizer-vs-pesticide sub-category on inventory items (both share `fertilizer_agrochemical`). Per-hectare rate calculation and enforcement of `pre_harvest_interval_days` against harvest dates are not implemented.
