# Operational records and population ledger — Phase 8

All paths below have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side. Authenticated, verified, onboarded, active accounts and farm membership are required. Unknown/foreign cycle, record, attachment and correction IDs return `404 not_found`.

## Endpoints and permissions

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/master/record-types` | `record.view` | 200, type schemas |
| GET | `/record-types/{type}/schema` | `record.view` | 200, one schema; unknown type 404 |
| GET | `/records` | `record.view` | 200, paginated records |
| POST | `/records` | `record.create` | 201, saved record or identical retry |
| GET | `/records/{record}` | `record.view` | 200, record including evidence and reversal link |
| POST | `/records/{record}/reverse` | `record.reverse` | 201, new reversal or identical retry |
| POST | `/records/{record}/attachments` | `record.create` | 201, private attachment metadata |
| GET | `/records/{record}/attachments/{attachment}` | `record.view` | 200, authenticated file download |

Owner and Manager have all four permissions: view, create, reverse, adjust. Farm Worker has view/create for common records; assignment-specific restrictions are not implemented yet. Finance has view only. `population_adjustment` additionally requires `record.adjust`; a replacement with `corrects_record_id` additionally requires `record.reverse`. No subscription entitlement is added. Writes share a 120/hour/user rate limit. There are no record PATCH/DELETE endpoints, direct population setters, inventory writes other than the optional `feed_use` stock link (see [Phase 9](PHASE-09-INVENTORY.md)), or automatic tasks.

## Creating an event

```json
{
  "type": "mortality",
  "production_cycle_id": "<cycle-uuid>",
  "recorded_at": "2026-02-01T10:00:00+01:00",
  "idempotency_key": "device-a:mortality:00042",
  "details": {"quantity": 5, "cause": "Unknown"},
  "notes": "Found during morning inspection"
}
```

Required common fields: `type` from the catalogue, `production_cycle_id` UUID, `details` object, `recorded_at` ISO date/time with seconds and explicit `Z` or `±HH:MM`, and `idempotency_key` (1–80 ASCII letters/digits/dot/underscore/colon/hyphen). Optional: `notes` nullable string up to 5000 characters and `corrects_record_id` nullable UUID. Never send `farm_id`, `created_by`, `population_delta` or calculated `measurement`; those fields are rejected. Unknown detail keys are rejected, including keys belonging to another record type.

`recorded_at` is the actual event time, stored in UTC independently of server `created_at`. It must be between the cycle's farm-local starting midnight and now. The cycle must be active. Closed cycles remain readable and return `409 cycle_closed` for new events, reversals and attachments; reopen with the existing authorized cycle endpoint first. An identical event retry remains readable even after closure. Closing a cycle reconciles the complete ledger and rejects an end date earlier than any recorded event.

## Type-specific details

All fields listed as required below are required within `details`. Text names identify the observed input; they are not inventory-item IDs. Only `feed_use` and the Phase 13 crop types (fertilizer, pesticide, planting, harvest) may carry an optional `details.inventory` link that consumes stock (Phase 9).

| Type | Kind/capability | Required details | Optional details | Effect |
|---|---|---|---|---|
| `feed_use` | Livestock/fish, `supports_feed_records` | `feed_name` ≤200; weight `components` | `context` (not allowed with `inventory`), `inventory` {item_id, storage_location_id, lot_id} | Feed-use history; with `details.inventory` also one stock-out movement (Phase 9) |
| `egg_collection` | Livestock, `produces_eggs` | Count `components` convertible to `piece` | `context`, `inventory` {storage_location_id} | Output history **and** one automatic stock-in (+quantity, reason `production`) on the farm's Eggs item, in the same transaction — see [Feed, eggs and milk stock](FEED-EGGS-MILK-STOCK.md) |
| `milk` | Livestock, `produces_milk` | Volume `components` | `context`, `inventory` {storage_location_id} | Output history **and** one automatic stock-in on the farm's Milk item (same rules as `egg_collection`) |
| `mortality` | Livestock/fish, `supports_mortality` | Positive whole `quantity`; `cause` ≤500 | — | Negative head movement |
| `weight` | Livestock/fish, `supports_live_weight` | Positive whole `sample_size`; weight `components` | `context` | Observed sample weight, no population effect |
| `temperature` | Livestock/fish | Exactly one temperature component | `context` | Measurement only |
| `water` | Livestock/fish | Volume `components` | `context` | Consumption history only |
| `irrigation` | Crop | `method` ≤200 | Volume `components`, `context`, `duration_minutes` integer 1–10080 | Activity only |
| `weeding` | Crop | `method` ≤200 | — | Activity only |
| `fertilizer_application` | Crop | `method` ≤200; `input_name` ≤200 (unless `inventory`); weight/volume `components` | `context`, `treated_area`, `concentration`, `inventory` (see Phase 13) | History; with `details.inventory` also one stock-out |
| `pesticide_application` | Crop | `product_type`, `method`; `input_name` (unless `inventory`); weight/volume `components` | `target`, `pre_harvest_interval_days`, `treated_area`, `concentration`, `inventory` (see Phase 13) | History; with `details.inventory` also one stock-out |
| `land_preparation` | Crop | `method` ≤200 | `treated_area` | Activity only (Phase 13) |
| `planting` | Crop | `units_planted` whole count | `method`, material `components` + `inventory` (seed/planting material) | Planting units capped by the baseline; optional seed stock-out (Phase 13) |
| `establishment_check` | Crop | `established_units` 0…baseline | — | Server derives failed units and survival % (Phase 13) |
| `growth_stage` | Crop | `stage` | `observation` | Observation only (Phase 13) |
| `crop_loss` | Crop | `units_lost`, `cause` | `affected_area` | Audit record; no population effect (Phase 13) |
| `crop_harvest` | Crop | weight/volume/count `components`, `inventory` (produce) | `quality` | One produce stock-in (Phase 13) |
| `pest_observation` | Crop | `issue` ≤500; `severity` = low/moderate/high | nullable `action` ≤2000 | Observation only |
| `general_note` | Either | `text` ≤5000 | — | Activity only |
| `population_adjustment` | Livestock/fish, manager permission | `expected_population`, `actual_population`, `reason` ≤2000 | — | Signed actual − expected movement |

Positive whole quantities have maximum 999999999999. Recount expected/actual counts are whole integers or canonical digit strings from 0 to 999999999999; negatives, fractions and booleans are rejected. `difference` is server-generated, never submitted.

The schema endpoints return the same registry used by backend validators: type, cycle kind, capability, field rules, measurement dimension/canonical unit/display unit/component limit, permission, population effect and `inventory_effect_enabled` (true for `feed_use`, `fertilizer_application` and `pesticide_application`; see `inventory_category`). Each schema also carries `permissions_required` (`{always, when_inventory_linked, when_correcting}` — authoritative; the legacy `permission` stays), `area_fields` (the area sub-objects, never the stock quantity) and `inventory` (`null` or `{mode: automatic_output|optional_link, direction, output, item_category, dimensions, optional, creates_item, object, fields}` describing the stock integration). The record types valid for one cycle are returned by `GET /production-cycles/{cycle}` as `available_record_types[]`, computed by the same rules the create endpoint applies (cycle kind, enabled species capability, open cycle). Species capability records are authoritative. In the current seed catalogue, milk is not enabled for any species; it becomes selectable when `produces_milk` is configured. This phase does not infer dairy capability from species names or add an administration UI.

Crop records never append population movements, change planting baselines or infer material quantity from planting units. Crop establishment, harvest, health treatments and cost posting are outside this phase.

## Measurements and compound entry

```json
{
  "type": "egg_collection",
  "production_cycle_id": "<cycle-uuid>",
  "recorded_at": "2026-02-01T10:00:00Z",
  "idempotency_key": "eggs-42",
  "details": {
    "components": [{"quantity": "3", "unit": "crate"}, {"quantity": "14", "unit": "piece"}],
    "context": {"type": "custom", "id": "<eggs-measurement-context-uuid>"}
  }
}
```

With the farm's configured conversion of 30 pieces/crate this records 104 eggs. Without a configured conversion it fails rather than assuming tray/crate size. Use Phase 5 unit and conversion endpoints. Components are 1–10 `{quantity,unit}` objects (temperature exactly one); scalar decimal quantities have at most 12 integer and 6 fractional digits. Fractions are forbidden for discrete units; negative values are permitted only for temperatures. Zero physical measurements are allowed. Context is `{type,id}` using the Phase 5 context enum and a visible active identity; required for package conversion and validated whenever provided with components. Irrigation can omit measurements when no reliable quantity was taken.

The stored `measurement` contains `entered`, `normalized`, `total` and self-contained `snapshot`. Canonical units are grams (`g`), millilitres (`ml`), pieces (`piece`), Celsius (`celsius`) or head (`head`). Display totals use kg, l, piece, celsius or head as appropriate. `weight` is the entered sample observation with explicit sample size; the API does not extrapolate total flock weight. Count families are distinct: heads cannot replace eggs. Historical snapshots and retries remain stable after conversion configuration changes. Feed/egg/milk records do not create inventory; Phase 9 is not enabled.

## Population, reconciliation and correction

Current livestock/fish population remains **SUM(population_movements.quantity)**, including the single immutable Phase 7 initial movement. The initial subtype count is historical metadata, never a second mutable balance. Mortality appends a decrease. Manager reconciliation appends an increase, decrease or audited zero difference:

```json
{
  "type": "population_adjustment",
  "production_cycle_id": "<cycle-uuid>",
  "recorded_at": "2026-03-01T10:00:00Z",
  "idempotency_key": "recount-42",
  "details": {"expected_population": 95, "actual_population": 97, "reason": "Physical recount"}
}
```

Expected must equal the locked current population (`409 population_changed` otherwise); actual is the observed count. Adjustment dates cannot precede an existing population movement. The result stores expected 95, actual 97, difference +2 and reason. A zero current population is valid. Writes also check every dated running balance, so backdated mortality cannot create a historically negative population even when today's total would remain positive.

Reverse an erroneous record:

```http
POST /api/v1/records/<record-uuid>/reverse
```

```json
{"reason":"Wrong count entered","recorded_at":"2026-03-02T10:00:00Z","idempotency_key":"reverse-42"}
```

This creates a new `type:reversal` record with `reverses_record_id`, reason, actor and timestamp. Its delta is the opposite of the original, and its measurement snapshot references the original observed quantity (consumers must use the reversal relationship rather than sum positive measurement objects). Original records, timestamps, evidence and movements remain unchanged. Reversal date must be at/after the original event. Reversing an increase may fail if those heads have since been consumed; resolve the real count instead of forcing a negative balance. Reversals cannot themselves be reversed, and each original can be reversed once.

To replace, first reverse, then POST a validated record of the same type and cycle with `corrects_record_id` referencing the original. One replacement per original; a replacement can itself later be reversed/replaced. These are two explicit transactions: if replacement validation fails, the reversal remains visible. Historical date checks still apply, so do not submit a replacement at a date where its decrease would overlap the unreversed original and make the dated balance negative. No automatic rewrite or deletion is performed.

Farm and cycle row locks serialize population changes against other records and cycle closure. Records and movements commit atomically with up to three deadlock attempts. Unique farm retry key, unique movement source/record links, unique reversal/replacement links and composite farm/cycle foreign keys reinforce this. Same retry key and same validated payload return the same record with 201; changed payload gives `409 idempotency_conflict`. Object key ordering is ignored, but numeric/string representations and omitted/null fields differ. The client must reuse the key for the same real-world event; two genuinely different keys are treated as different events. No heuristic duplicate-event detection is attempted. Failed transactions consume no key and dispatch no `RecordCreated` event; successful new records dispatch after commit.

## Responses, lists and errors

Create/show/reverse return the standard envelope:

```json
{
  "data": {
    "id": "<record-uuid>", "production_cycle_id": "<cycle-uuid>", "type": "mortality",
    "details": {"quantity":5,"cause":"Unknown"},
    "measurement": {"entered":[{"quantity":"5","unit":"head"}],"normalized":{"quantity":"5","unit":"head"},"total":{"quantity":"5","unit":"head"},"snapshot":{}},
    "population_delta": -5, "recorded_at": "2026-02-01T09:00:00.000000Z",
    "notes": "Found during morning inspection", "reverses_record_id": null,
    "corrects_record_id": null, "reversed_by_record_id": null,
    "created_by": "<user-uuid>", "created_at": "2026-02-02T08:00:00.000000Z", "attachments": []
  },
  "meta": {}, "message": "Operational record saved."
}
```

The snapshot above is abbreviated; actual responses contain complete replayable conversion metadata. UUIDs are v7 for new record/attachment/movement identities. A record's `measurement` can be null. Reversed originals remain in list results with `reversed_by_record_id`; reversal records are also listed. Retry keys/hashes and private file paths are not exposed.

List query parameters: optional `production_cycle_id` UUID, `type` (including reversal), `recorded_from` and `recorded_to` as inclusive farm-local YYYY-MM-DD days, `page` ≥1 and `per_page` 1–100 (default 50). Date bounds can be used independently; to must be >= from when both exist. Order is recorded_at descending, UUID descending. Metadata: current_page, per_page, last_page, total. No implicit active-only filter.

Validation example:

```json
{"message":"The given data was invalid.","code":"validation_failed","request_id":"<request-uuid>","errors":{"details.quantity":["A positive whole count is required."]}}
```

Error envelope messages may vary; branch on code. Common errors: 401 unauthenticated; 403 forbidden/account/email/onboarding/farm access errors; 404 not_found; 419 CSRF/session failure; 422 validation_failed or Phase 5 measurement errors (`unit_dimension_mismatch`, `incompatible_units`, `invalid_quantity`, `unknown_unit`, `unit_not_selectable`, `conversion_context_required`, `conversion_not_configured`, `ambiguous_conversion`); 429 throttling. Conflict codes: `cycle_closed`, `insufficient_population`, `population_changed`, `record_already_reversed`, `invalid_correction`, `idempotency_conflict`, `cycle_reconciliation_failed`, `attachment_limit_reached`. 503 `attachment_storage_failed` is retryable. Reconciliation failures indicate inconsistent stored sources, not permission to edit the balance.

## Private evidence

Upload `multipart/form-data` with field `file`; retain CSRF/session headers and request JSON error responses. Defaults in `config/records.php`: max 10 MiB/file, 10 attachments/record; jpg/jpeg/png/webp/pdf/csv/txt/xls/xlsx. Extension and server-detected content must agree. SVG, HTML and executable formats are excluded. Files get generated storage names on the private local disk; user filenames are display/download metadata only. Identical file SHA-256 for the same record returns the existing attachment. Filesystem writes are compensated if the DB transaction fails. Uploading evidence does not affect population.

Attachment response: `{data:{id,original_name,mime_type,size,created_by,created_at,download_url},meta:{},message:"Evidence attached."}`. GET download_url requires the same authenticated farm access and parent record ownership, returns `Content-Disposition: attachment`, `application/octet-stream`, `X-Content-Type-Options:nosniff` and private/no-store caching. No public or unprotected URL is returned. Attachments remain readable after reversal/closure and cannot be replaced/deleted through this API. Virus scanning and remote object storage are not implemented.
