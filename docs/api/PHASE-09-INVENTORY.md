# Inventory, lots, stock movements and feed formulas — Phase 9

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side; **never send `farm_id`** (rejected 422). Unknown or foreign item, lot, movement, storage-location, formula and record IDs return `404 not_found`. Responses use the standard envelope `{data, meta, message}`; errors use `{message, code, request_id, errors?, details?}`.

## The one rule: stock is derived from movements

An inventory item has **no quantity field**. Current stock is always `SUM(inventory_movements.quantity_delta)`, per item, per storage location and per lot. There is no endpoint that overwrites a balance (no `PATCH quantity_on_hand`); sending `quantity`, `quantity_on_hand`, `quantity_delta`, `balance`, `stock`, `type`, `created_by` or `farm_id` to any write endpoint is a 422. Every change is an append-only row (stock-in, stock-out, adjustment, transfer legs, reversal). Corrections are new rows; nothing is edited or deleted.

| Movement `type` | Sign | Created by |
|---|---|---|
| `stock_in` | + | `POST /inventory/stock-in` (reason `opening_balance`, `purchase`, `donation`, `aid`, `received`, `production` (not for produce), `other`); egg/milk/crop production and eggs returned from a cancelled incubation are written by their own workflow (reasons `production`, `harvest`, `returned`) |
| `stock_out` | − | `POST /inventory/stock-out` (reason `donation`, `internal_use`, `damaged`, `spoiled`, `lost`, `disposal`, `other`; legacy `use` (not for feed), `expired`, `wasted`), linked feed-use records (reason `production_use`), sales (`sale`) and incubation (`incubation`) |
| `adjustment` | ± or 0 | `POST /inventory/adjustments` (physical count reconciliation) |
| `transfer_out` / `transfer_in` | − / + | `POST /inventory/transfers` (always a linked pair) |
| `reversal` | opposite of the original | `POST /inventory/movements/{id}/reverse`, or reversing a linked operational record |

## Endpoints and permissions

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/master/inventory-options` | `inventory.view` | 200 categories, sellable categories, movement types, reason codes and the labelled per-item-kind `reasons` catalogue (`by_item_kind` is authoritative; the flat `reasons.in/out` are deprecated and carry `manual_for_kinds`; every route has `path` + absolute `url`) |

Every inventory item resource carries `kind` (`feed` \| `eggs` \| `milk` \| `general`) and `is_system_managed` (true for the farm's automatic Eggs / Milk items).
| GET | `/inventory/output-balances` | `inventory.view` | 200 read-only available eggs / milk (never creates items) |
| GET | `/inventory/items` | `inventory.view` | 200 paginated items with derived stock |
| POST | `/inventory/items` | `inventory.manage` | 201 |
| GET | `/inventory/items/{item}` | `inventory.view` | 200 with `balances` by location and lot |
| PATCH | `/inventory/items/{item}` | `inventory.manage` | 200 |
| GET | `/inventory/items/{item}/movements` | `inventory.view` | 200 paginated ledger of one item |
| GET | `/inventory/movements` | `inventory.view` | 200 paginated farm ledger |
| GET | `/inventory/movements/{movement}` | `inventory.view` | 200 |
| POST | `/inventory/movements/{movement}/reverse` | `inventory.adjust` | 201 array of reversal movement(s) |
| GET | `/inventory/lots` | `inventory.view` | 200 paginated lots with stock |
| POST | `/inventory/stock-in` | `inventory.manage` | 201 movement |
| POST | `/inventory/stock-out` | `inventory.use` | 201 movement |
| POST | `/inventory/adjustments` | `inventory.adjust` | 201 movement |
| POST | `/inventory/transfers` | `inventory.manage` | 201 `{transfer_group_id, movements:[out,in]}` |
| GET/POST | `/feed-formulas` | `inventory.view` / `inventory.manage` | 200 / 201 |
| GET/PATCH | `/feed-formulas/{formula}` | `inventory.view` / `inventory.manage` | 200 |

Roles: Owner and Manager have all four permissions (`view`, `use`, `manage`, `adjust`). Farm Worker has `view` and `use` (issue stock and link feed-use records) but cannot create items, receive stock, transfer, adjust or edit formulas. Finance has `view` only. No subscription entitlement gates inventory. Writes share a 240/hour/user rate limit (429).

## Items

`POST /inventory/items`

```json
{"name": "Layer mash", "category": "feed", "stock_unit": "kg",
 "tracks_lots": true, "tracks_expiry": true,
 "low_stock_threshold": {"quantity": "50", "unit": "kg"}, "description": "20 kg bags"}
```

- `category`: `feed`, `medicine`, `seed_planting_material`, `fertilizer_agrochemical`, `general_supply`, `produce` (receives crop harvests, Phase 13). Phase 9 attaches no category-specific schema (medicine packaging/dose arrive with Health).
- `stock_unit`: a weight, volume or count unit code (`kg`, `g`, `l`, `ml`, `piece`, `egg`, `head`, ...). This fixes the item's **measurement basis**; incompatible units can never enter the same balance (`422 unit_dimension_mismatch` / `incompatible_units`). Packages (`bag`, `crate`...) are never a stock unit.
- `tracks_expiry` requires `tracks_lots`. `low_stock_threshold` is in a unit of the item's basis.
- Names are unique per farm after trimming/case-folding (409 `inventory_item_exists`).
- There is no opening quantity: receive opening stock with `stock-in` and reason `opening_balance`.
- `PATCH` can change name, description, threshold and `is_active` any time. `category`, `stock_unit`, `tracks_lots`, `tracks_expiry` are **locked after the first movement** (409 `item_has_movements`). Deactivation requires zero stock (409 `item_has_stock`); inactive items reject new stock-in, stock-out, adjustments, transfers and feed links (409 `item_inactive`) but reversals stay possible.

Item resource: `id, name, category, description, stock_unit, dimension, tracks_lots, tracks_expiry, is_active, stock, low_stock_threshold, is_low_stock, created_at, updated_at` (+ `balances` on `GET /items/{item}`).

```json
"stock": {"quantity": "618", "unit": "kg", "normalized": {"quantity": "618000", "unit": "g"}},
"low_stock_threshold": {"quantity": "50", "unit": "kg"}, "is_low_stock": false,
"balances": [{"storage_location_id": "…", "inventory_lot_id": "…", "quantity": "618", "unit": "kg", "normalized": {"quantity": "618000", "unit": "g"}}]
```

`is_low_stock` is true when a threshold exists and total stock is **at or below** it. List filters: `category`, `search` (literal name substring), `include_inactive` (`1`/`0`, default `0`), `low_stock` (`1`/`0`), `page`, `per_page` (1–100, default 50). Order: name, id.

## Quantities, packages and conversion contexts (Phase 5 reuse)

Every quantity is a `components` list of entered parts, normalised by the Phase 5 normaliser: `[{"quantity": "12", "unit": "bag"}, {"quantity": "18", "unit": "kg"}]`. Quantities are decimal strings (≤12 integer digits, ≤6 decimals). Entered parts, the normalised total (canonical unit `g`, `ml` or the count unit) and the replayable conversion snapshot are stored on the movement.

**A package has no universal size.** `bag`/`crate`/`bottle` resolve only through *this item's own* conversion. Create it with the existing endpoint, using the new context type:

```json
POST /settings/package-conversions
{"context_type": "inventory_item", "context_id": "<item-uuid>", "package_unit": "bag", "target_unit": "kg", "quantity_per_package": "50"}
```

Without it: `422 conversion_not_configured`. The item must be an active item of the current farm (else 422 on `context_id`). Changing the conversion later never changes recorded history: each movement carries its own snapshot.

## Storage locations

`storage_location_id` is a Phase 6 storage location of the current farm (foreign/unknown → 404). Receiving into, or transferring to, a location requires it to be active with active ancestors (`409 location_inactive`). Stock can always **leave** an inactive location (stock-out, transfer-out, adjustment, reversal). Stock is tracked per (item, storage location, lot); there is no physical capacity.

## Lots and expiry

Lots are per item and immutable (code and expiry never change). Rules:

- Item **does not track lots**: `lot_id`/`lot` are rejected (422).
- Item **tracks lots**: stock-in needs `lot_id` or `lot: {"code": "L-2026-07", "expires_on": "2027-01-31"}`; an existing code of the same item (case-insensitive) is reused, and re-sending it with a different expiry is rejected. Stock-out, adjustment and transfer need `lot_id` — **there is no automatic FIFO/FEFO**; the client chooses the lot. A lot id from another item or farm is 404.
- Item **tracks expiry**: `lot.expires_on` is required for a new lot and `expires_on` must not be sent for non-expiry items.
- Expired means `expires_on` is before today's date in the farm timezone (or, for historical events, before the event's farm-local date). Receiving into an expired lot is `409 lot_expired`. Issuing an expired lot with reason `use` (including feed-use records) is `409 lot_expired`; write it off with reason `expired`/`wasted`/`damaged`/`other`, or transfer or count it.

`GET /inventory/lots`: filters `inventory_item_id`, `expired` (`1`/`0`), `expiring_within_days`, `include_empty` (default `0`: zero-stock lots hidden), paging. Soonest expiry first. Lot resource: `id, inventory_item_id, code, expires_on, is_expired, stock, created_at`.

## Writing movements

Common body fields: `inventory_item_id`, `storage_location_id`, `components`, `recorded_at` (ISO with seconds and explicit `Z`/`±HH:MM`, not in the future; stored in UTC separately from `created_at`), `idempotency_key` (≤80 chars `[A-Za-z0-9._:-]`), optional `notes`.

```json
POST /inventory/stock-in
{"inventory_item_id": "…", "storage_location_id": "…", "reason": "purchase",
 "lot": {"code": "L-2026-07", "expires_on": "2027-01-31"},
 "components": [{"quantity": "12", "unit": "bag"}, {"quantity": "18", "unit": "kg"}],
 "recorded_at": "2026-10-01T08:30:00+01:00", "idempotency_key": "device-a:stockin:0007", "notes": "Delivery note 7"}
```

```json
{"data": {"id": "…", "inventory_item_id": "…", "storage_location_id": "…", "inventory_lot_id": "…",
  "lot": {"code": "L-2026-07", "expires_on": "2027-01-31"}, "type": "stock_in", "reason": "purchase", "justification": null,
  "quantity_delta": {"quantity": "618000", "unit": "g"}, "quantity_delta_display": {"quantity": "618", "unit": "kg"},
  "measurement": {"entered": [], "normalized": {}, "total": {}, "snapshot": {}},
  "recorded_at": "2026-10-01T07:30:00.000000Z", "notes": "Delivery note 7", "operational_record_id": null,
  "transfer_group_id": null, "reverses_movement_id": null, "reversed_by_movement_id": null,
  "created_by": "…", "created_at": "…"}, "meta": {}, "message": "Stock received."}
```

### Stock-out
`POST /inventory/stock-out` with `reason` (`donation`, `internal_use`, `damaged`, `spoiled`, `lost`, `disposal`, `other`, legacy `use` (`422` for feed items: use a `feed_use` record) / `expired` / `wasted`; `sale`, `production_use` and `incubation` are owned by `/sales`, `feed_use` records and breeding projects and are `422` here) — same body shape. `output: eggs|milk` may replace `inventory_item_id` (see the Feed, eggs and milk contract). Stock can **never go negative**: under a per-farm lock the affected (item, location, lot) bucket is re-checked, and the whole dated history is replayed so a back-dated issue that would have been negative at that moment is also rejected. `409 insufficient_stock` carries `details.available` and `details.requested`; nothing is written.

### Adjustments (physical counts)
```json
POST /inventory/adjustments
{"inventory_item_id": "…", "storage_location_id": "…", "lot_id": "…",
 "expected": [{"quantity": "98", "unit": "kg"}], "counted": [{"quantity": "95", "unit": "kg"}],
 "reason": "Monthly count", "recorded_at": "…", "idempotency_key": "…"}
```
The server stores a signed `adjustment` movement of counted − expected (here −3 kg) after confirming `expected` equals the current ledger balance of that bucket (`409 stock_changed`, `details.current`). `counted` may be zero; an unchanged count is stored as an audited zero-delta verification. A count cannot be dated before existing movements of the same stock (422 on `recorded_at`). The free-text `reason` is returned as `justification`.

### Transfers
`POST /inventory/transfers` with `from_storage_location_id`, `to_storage_location_id` (different), item, optional `lot_id`, `components`, `recorded_at`, `idempotency_key`. One transaction writes a `transfer_out` and a `transfer_in` sharing `transfer_group_id`; the same lot arrives. Insufficient stock at the source writes nothing. A retry returns the same pair.

### Reversals
`POST /inventory/movements/{id}/reverse` with `reason`, `recorded_at` (not before the original), `idempotency_key`. Appends compensating `reversal` row(s) (both legs of a transfer together); the original stays visible with `reversed_by_movement_id`. Errors: `409 movement_already_reversed` (reversal-of-reversal or repeat), `409 reverse_via_record` (movement belongs to an operational record — reverse the record), `409 insufficient_stock` (e.g. reversing a receipt that has since been used).

### Idempotency
`idempotency_key` is unique per farm. Same key + same validated payload returns the original movement(s) with 201 and writes nothing; same key with a different payload, or on a different endpoint, is `409 idempotency_conflict`. A failed attempt never consumes its key. Keep one key per real-world event.

## Movement list

`GET /inventory/movements` and `GET /inventory/items/{item}/movements`: filters `inventory_item_id`, `storage_location_id`, `inventory_lot_id`, `type`, `operational_record_id`, `recorded_from`, `recorded_to` (farm-local `YYYY-MM-DD`, inclusive), `page`, `per_page` (1–100, default 50). Order `recorded_at` desc, id desc.

## Phase 8 integration: feed use consumes stock

A `feed_use` record may carry `details.inventory`:

```json
POST /records
{"type": "feed_use", "production_cycle_id": "…", "recorded_at": "…", "idempotency_key": "…",
 "details": {"feed_name": "Layer mash", "components": [{"quantity": "2", "unit": "bag"}],
             "inventory": {"item_id": "…", "storage_location_id": "…", "lot_id": "…"}}}
```

- Requires `record.create` **and** `inventory.use`. The item must be an active **feed** item measured by **weight**; `lot_id` follows the lot rules above. `details.context` must be omitted — the quantity is measured against the inventory item (its own bag conversion applies).
- In the same transaction the record and exactly one `stock_out` movement (reason `production_use` — it was `use` before the reason catalogue was extended — `operational_record_id` = the record, same `recorded_at` and measurement snapshot) are written. The record resource exposes `inventory_movement_id`. A retry with the same record `idempotency_key` returns the original record and writes nothing more.
- Insufficient stock, an expired lot, an inactive item/location mismatch or any validation error rolls the whole record back.
- Reversing the record (`POST /records/{record}/reverse`) appends a compensating `reversal` movement linked to the reversal record. Correcting uses the existing reverse + `corrects_record_id` flow; the replacement consumes stock again.
- A `feed_use` without `details.inventory` stays a pure record with no stock effect. Crop harvest stock-in is owned by Phase 13 (`crop_harvest` records into `produce` items). **Egg and milk production now also stock in automatically** through `egg_collection` / `milk` records, with an automatic Eggs/Milk item — see [Feed, eggs and milk stock](FEED-EGGS-MILK-STOCK.md) (which also defines the full reason catalogue, `output` shorthand, incubation consumption and feed sales).

## Feed formulas

A formula is a recipe, **not stock**. Creating/editing one never creates a movement and an ingredient's optional `inventory_item_id` is reference only.

```json
POST /feed-formulas
{"name": "Grower 18%", "species_id": "<optional-species-uuid>", "description": "…",
 "items": [{"ingredient_name": "Maize", "inclusion_percent": "60.5", "inventory_item_id": "<optional>"},
           {"ingredient_name": "Soya", "inclusion_percent": "39.5"}]}
```

Percentages are >0, ≤100, ≤4 decimals, and **must total exactly 100**; ingredient names (and linked items) may appear once; linked items must belong to the farm. `PATCH` accepts `name`, `description`, `species_id`, `is_active` and a full replacement `items` list, which increments `version`. Names are unique per farm (409 `feed_formula_exists`). List filters: `search`, `include_inactive` (`1`/`0`), paging.

## Error codes

| Status | `code` | Meaning |
|---|---|---|
| 409 | `insufficient_stock` | Would make a bucket negative (now or in dated history); `details.available/requested` when computable |
| 409 | `stock_changed` | Adjustment `expected` is stale |
| 409 | `item_inactive`, `item_has_stock`, `item_has_movements`, `inventory_item_exists` | Item lifecycle/uniqueness |
| 409 | `location_inactive` | Receiving into an inactive storage location (or inactive ancestor) |
| 409 | `lot_expired` | Receiving into, or using, an expired lot |
| 409 | `movement_already_reversed`, `reverse_via_record` | Reversal rules |
| 409 | `idempotency_conflict` | Key reused with a different payload |
| 409 | `feed_formula_exists` | Duplicate formula name |
| 422 | `conversion_not_configured`, `conversion_context_required`, `unit_dimension_mismatch`, `incompatible_units`, `invalid_quantity` | Phase 5 measurement errors |
| 422 | `validation_failed` | Field errors (lot rules, forbidden fields, bad dates, formula totals) |
| 403 / 404 / 429 | `forbidden` / `not_found` / throttled | Permission, foreign or unknown resources, rate limit |

Phase 8 record errors (`cycle_closed`, `idempotency_conflict`, ...) apply unchanged to linked feed-use records.

## Deliberately not in this phase

Purchasing, suppliers and expenses; sales and produce stock; medicine packaging, dose and withdrawal (Health); automatic FIFO/FEFO or expiry write-offs; physical store capacity; stock valuation/costing; low-stock notifications (an `InventoryMovementRecorded` event is dispatched after commit for later phases).
