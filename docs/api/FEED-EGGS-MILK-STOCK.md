# Feed, eggs and milk — one event, one entry, every effect

Frontend integration contract for **Feed IN/OUT**, **Eggs IN/OUT** and **Milk IN/OUT**. It extends the existing inventory ledger ([Phase 9](PHASE-09-INVENTORY.md)), operational records ([Phase 8](PHASE-08-RECORDS.md)), breeding ([Phase 11](PHASE-11-BREEDING.md)) and sales ([Phase 15](PHASE-15-SALES-INVOICES.md)). There is no parallel stock system.

> **Principle.** The farmer records the real-world event **once**. The backend writes every required effect in **one transaction**, or none of them. Stock is never stored: the **available balance is `SUM(inventory_movements.quantity_delta)`**. *Production totals* ("eggs collected today: 104") and *available stock* ("eggs on hand: 44") are two independent, equally true numbers — never derive one from the other.

## 1. What the user does → what the backend writes

| Real-world event | The ONE call | Operational record | Inventory movement | Other effects |
|---|---|---|---|---|
| Collected 3 crates + 14 eggs from Layers A | `POST /records` `egg_collection` | `egg_collection` (104 pieces) | `stock_in` **+104**, reason `production` | production metrics, dashboard, reports unchanged (they read the record) |
| Produced 50 L milk from Dairy Herd A | `POST /records` `milk` | `milk` (50 L) | `stock_in` **+50 L**, reason `production` | production metrics |
| Used 25 kg Starter for Broiler Batch A | `POST /records` `feed_use` with `details.inventory` | `feed_use` | `stock_out` **−25 kg**, reason `production_use` | batch history |
| Put 40 eggs into Incubator A | `POST /breeding-projects` with `consume_egg_stock: true` | – (a breeding project) | `stock_out` **−40**, reason `incubation`, linked to the project | project `egg_stock` |
| Sold 2 crates of eggs / 25 L milk / 50 kg surplus feed | `POST /sales` stock line | – | `stock_out`, reason `sale`, linked to the sale line | invoice, income on payment |
| Gave away 1 crate | `POST /inventory/stock-out` reason `donation` | – | `stock_out` −30 | no sale, no income |
| Eggs broken / milk spoiled / feed lost | `POST /inventory/stock-out` reason `damaged` / `spoiled` / `lost` | – | `stock_out` | – |
| Eggs/milk/feed donated, bought, received | `POST /inventory/stock-in` (or `POST /purchases` to book the expense too) | **none** | `stock_in` | no production record, ever |
| Opening stock | `POST /inventory/stock-in` reason `opening_balance` | – | `stock_in` | – |
| Count correction | `POST /inventory/adjustments` | – | `adjustment` (signed) | – |

**Which events create an operational record:** only `egg_collection` (eggs laid by this farm's livestock), `milk` (milk produced by this farm's livestock) and `feed_use` (feed consumed by a cycle). **Everything else is inventory-only** (or owned by its own document: sale, breeding project, purchase).

`egg_collection` stays semantically pure: it always means *eggs produced by one of this farm's livestock cycles*. Donated, purchased or received eggs are never an `egg_collection`; the frontend's "Eggs In" screen routes by source:

```text
Produced on farm      -> POST /records            { type: egg_collection }   (record + stock-in)
Purchased             -> POST /inventory/stock-in { output: eggs, reason: purchase }   or POST /purchases
Gift / Donation       -> POST /inventory/stock-in { output: eggs, reason: donation }
Received              -> POST /inventory/stock-in { output: eggs, reason: received }
Other                 -> POST /inventory/stock-in { output: eggs, reason: other }
```
Milk is identical with `milk` / `output: milk`.

## 2. Reasons are discovered, not hard-coded

`GET /master/inventory-options` (permission `inventory.view`) now returns:

```json
{
  "sellable_categories": ["produce", "feed"],
  "stock_in_reasons":  ["opening_balance","purchase","donation","aid","received","production","returned","other"],
  "stock_out_reasons": ["use","damaged","expired","wasted","other","sale","production_use","incubation","donation","internal_use","spoiled","lost","disposal"],
  "reasons": {
    "in":  [{ "code": "production", "label": "Produced on farm", "manual": true, "legacy": false, "route": null }],
    "out": [{ "code": "incubation", "label": "Put into incubation", "manual": false, "legacy": false,
              "route": { "kind": "breeding_project", "method": "POST", "path": "/breeding-projects", "workflow": "incubation", "field": "consume_egg_stock", "note": "..." } }],
    "by_item_kind": { "feed": {"in": [], "out": []}, "eggs": {"in": [], "out": []}, "milk": {"in": [], "out": []}, "general": {"in": [], "out": []} }
  }
}
```

* `by_item_kind.{feed|eggs|milk|general}.{in|out}` is the **display-ordered list of actions** for that kind of stock. Each entry: `code`, `label`, `manual` (accepted on `/inventory/stock-in|stock-out`), `creates_operational_record`, and `route` — the **authoritative endpoint** that records the event. Render the labels; call the `route`.
* Item kind: `eggs` = the farm's Eggs item, `milk` = the farm's Milk item, `feed` = category `feed`, otherwise `general`.
* `manual: false` reasons are refused on the manual endpoints with `422 validation_failed` on `reason`, and the message names the endpoint to use. That is what stops one event being entered — and decremented — twice.
* Reasons are **domain-level and stable**. Channel detail (market, online, restaurant…) belongs to the sale, not to a stock reason.
* `transfer_in` / `transfer_out` / `adjustment` appear in the lists with a `route` to `/inventory/transfers` and `/inventory/adjustments`; they are movement *types*, not reasons.
* `use`, `expired`, `wasted` are legacy codes kept for existing clients and history (`legacy: true`). `use` is labelled "Other use". It is **refused for feed items** (`422` on `reason`, naming `POST /records` type `feed_use`): feed given to livestock is entered once, through `feed_use` (`production_use`).

| IN reason | Label | Manual | Notes |
|---|---|---|---|
| `purchase` | Purchased | yes | stock only; `POST /purchases` books the expense too |
| `donation` | Gift / Donation (feed: Donation / Sharing) | yes | |
| `aid` | Aid / Support | yes | |
| `received` | Received | yes | from another farm / supplier |
| `production` | Produced on farm | yes — **not for produce** | feed made on the farm. For eggs, milk and crops it is written only by the record (422 on the manual endpoint) |
| `opening_balance` | Opening balance | yes | |
| `returned` | Returned to stock | **no** | breeding workflow only |
| `other` | Other | yes | |

| OUT reason | Label | Manual | Authoritative path |
|---|---|---|---|
| `sale` | Sold | no | `POST /sales` |
| `production_use` | Used for livestock | no | `POST /records` `feed_use` |
| `incubation` | Put into incubation | no | `POST /breeding-projects` |
| `donation` | Gift / Donation | yes | |
| `internal_use` | Personal / Internal use | yes | |
| `damaged` | Damaged / Broken | yes | |
| `spoiled` | Spoiled (feed, milk: Spoiled / Contaminated) | yes | |
| `lost` | Lost / Stolen | yes | |
| `disposal` | Disposal / Compost | yes | |
| `other` | Other | yes | |
| `use`, `expired`, `wasted` | legacy | yes | |

## 3. Eggs and milk need no inventory setup

Eggs and milk are each **one inventory item per farm**, created automatically the first time they are needed:

| | Eggs | Milk |
|---|---|---|
| `system_key` | `output:eggs` | `output:milk` |
| Category | `produce` | `produce` |
| Stock unit | `piece` (count) | `l` (volume; canonical `ml`) |
| Tracks lots / expiry | no | no |

* Resolution is deterministic, tenant-scoped and concurrency-safe: it runs inside the request's transaction **under the farm row lock**, and the unique index `(farm_id, system_key)` is the backstop. Two simultaneous first collections create one item.
* **Adoption.** A farmer's own item named `Eggs` / `Milk` is adopted (only `system_key` is set; name, description, threshold… are untouched) when it is in the same farm, category `produce`, active, not lot/expiry-tracked, and in the right unit family (`piece` / volume). Otherwise it is left alone and a separate item named `Eggs (farm output)` / `Milk (farm output)` is created.
* **Sale restrictions work unchanged**: the item is `produce`, so it is sellable.
* **Reads never create anything.** `GET /inventory/output-balances` reports `exists: false` and a zero balance for a farm that never stocked eggs/milk.
* Frontend shorthand: `output: "eggs" | "milk"` replaces `inventory_item_id` on `POST /inventory/stock-in`, `POST /inventory/stock-out` and, as an item-line field, on sale and purchase stock lines (below). Every inventory item resource exposes `kind` (`feed | eggs | milk | general`) and `is_system_managed`, and the flat `reasons.in/out` of `GET /master/inventory-options` are deprecated in favour of `by_item_kind` (flat entries carry `manual_for_kinds`). `stock-out` with `output` never creates the item (409 `insufficient_stock` if there is none).

### Storage location

`inventory_movements.storage_location_id` is mandatory, but the farmer never has to understand it:

1. an explicit `details.inventory.storage_location_id` / `storage_location_id` / `egg_storage_location_id` is used (must be an active store of this farm — foreign ids are 404);
2. else, if the farm has **exactly one** active storage location, it is used;
3. else, if the farm has **none**, a root storage location **"Main Store"** (`type: store`) is created once (a taken name becomes "Main Store 2"…; an inactive store is never reactivated). Only IN paths create it — taking stock out never does;
4. else (several active stores, none named) → `422` on the location field: **choose one**. The backend never guesses.

## 4. Endpoints

### `POST /records` — `egg_collection` and `milk` (permission `record.create` **and** `inventory.use`)

```json
{
  "production_cycle_id": "…",
  "type": "egg_collection",
  "details": {
    "components": [{ "quantity": "3", "unit": "crate" }, { "quantity": "14", "unit": "piece" }],
    "context": { "type": "custom", "id": "…" },
    "inventory": { "storage_location_id": "…" }
  },
  "recorded_at": "2026-10-14T08:30:00Z",
  "idempotency_key": "…"
}
```
* One request creates the record **and** a `stock_in` of the normalized quantity (104), reason `production`, linked to the record (`operational_record_id`) and the cycle (`production_cycle_id`).
* `details.inventory` is optional and only picks the store. The server stores `{ item_id, storage_location_id }` on the record so you can see where the stock went.
* Package units (crate, tray…) normalize **only when a conversion is configured**. Context: an explicit `details.context` wins; otherwise the Eggs/Milk item's own conversions (`POST /settings/package-conversions` with `context_type: inventory_item`, `context_id` = `inventory_item_id` from `GET /inventory/output-balances`). No conversion → `422 conversion_not_configured`. The entered parts (3 crates + 14 pieces) are preserved in `measurement.entered`; the sale line and the incubation read the same item conversions.
* A quantity of `0` records production but moves no stock.
* The capability still gates the type: `egg_collection` needs `produces_eggs`, `milk` needs **`produces_milk`** (`422 type` otherwise). Behaviour is capability-driven; the default seed enables `produces_milk` for cattle, goat, sheep, camel and water buffalo (see §8).
* Reversal: `POST /records/{id}/reverse` appends a compensating movement. It is `409 insufficient_stock` while those eggs/milk have since left stock — undo the downstream movement first (cancel the sale, etc.). The movement itself cannot be reversed through `/inventory/movements/{id}/reverse` (`409 reverse_via_record`).
* `GET /master/record-types` and `/record-types/{type}/schema` now report `inventory_effect_enabled: true`, `inventory_category: "produce"`, `inventory_direction: "in"`, `inventory_automatic: true`, `inventory_output: "eggs" | "milk"`, and (frontend-handoff pass) the structured `inventory: {mode: "automatic_output", direction: "in", output, item_category: "produce", dimensions, optional: false, creates_item: true, object: "details.inventory", fields: {storage_location_id: "optional"}}` and `permissions_required: {always: ["record.create", "inventory.use"], …}`.

### `POST /records` — `feed_use` (unchanged contract, new movement reason)

`details.inventory { item_id, storage_location_id, lot_id? }` + `production_cycle_id` = one entry that writes the `feed_use` record **and** the `stock_out` (now reason **`production_use`**, linked to the record and the cycle). Retrying the same `idempotency_key` never decrements twice. Without `details.inventory` it is still a pure record with no stock effect. Feed "used for livestock" is never a manual stock-out.

### `POST /inventory/stock-in` (permission `inventory.manage`)

New: `output: eggs|milk` instead of `inventory_item_id`; `storage_location_id` optional with `output`; new reasons `aid`, `received`, `production` (not for produce). A donation/purchase/received/other IN never creates a production record. `reason: production` on eggs, milk or any `produce` item → `422` pointing to `POST /records`.

### `POST /inventory/stock-out` (permission `inventory.use`)

New: `output: eggs|milk`; reasons `donation`, `internal_use`, `spoiled`, `lost`, `disposal`. `sale`, `production_use`, `incubation` → `422` naming the endpoint to use. Negative stock is impossible, now or in dated history (`409 insufficient_stock`).

### `GET /inventory/output-balances` (permission `inventory.view`, read-only)

```json
{ "data": {
  "eggs": { "kind": "eggs", "exists": true, "inventory_item_id": "…", "name": "Eggs", "dimension": "count", "unit": "piece",
            "available": { "quantity": "44", "unit": "piece" }, "available_normalized": { "quantity": "44", "unit": "piece" },
            "by_storage_location": [{ "storage_location_id": "…", "available": { "quantity": "44", "unit": "piece" } }] },
  "milk": { "kind": "milk", "exists": false, "inventory_item_id": null, "name": "Milk", "dimension": "volume", "unit": "l",
            "available": { "quantity": "0", "unit": "l" }, "available_normalized": { "quantity": "0", "unit": "ml" }, "by_storage_location": [] }
} }
```
It never creates the items or a Main Store. Show "Eggs collected today" from the dashboard/reports and "Eggs available" from here — they are independent.

### `GET /inventory/movements` and `/inventory/items/{item}/movements`

New response fields: `reason_label`, `production_cycle_id`, `breeding_project_id`, `source { type, id }` (`operational_record | sale | purchase | health_record | breeding_project | transfer | manual`). New filters: `production_cycle_id`, `breeding_project_id`, `reason`. Together with the existing `operational_record_id`, `sale_id`, `purchase_id`, `health_record_id` filters, every movement links to the event that explains it; `created_by` is the actor; `reverses_movement_id` / `reversed_by_movement_id` show corrections.

### `POST /breeding-projects` — put eggs into incubation (permissions `breeding.create` **and** `inventory.use`)

New optional fields (incubation only): `consume_egg_stock: true`, `egg_storage_location_id`. One transaction creates the project **and** a `stock_out` of `eggs_set` eggs, reason `incubation`, `breeding_project_id` + `production_cycle_id`. Too few eggs → `409 insufficient_stock` and **no project** is created. A retry with the same `idempotency_key` returns the same project and takes nothing more. Omit the flag and nothing touches stock (existing clients keep working). `consume_egg_stock` on a pregnancy project is `422`.

The project resource gains `egg_stock: { consumed, returned, net_out, movements[] }` (null when the project never touched stock), derived from its movements.

* **Recording the hatch** (`POST …/outcomes`) never changes egg stock — the eggs left stock when they were set.
* **Editing `eggs_set`** (`PATCH`) on a stock-linked project: raising it takes the extra eggs (409 if short); lowering it **returns nothing** unless `eggs_returned_to_stock` (≤ the reduction) says so.
* **Cancelling** (`POST …/cancel`): **no instruction = no inventory increase**. Eggs are never assumed to be usable again. To return some or all, send `eggs_returned_to_stock` (≥ 1, ≤ what the project took and has not returned) and optionally `egg_storage_location_id` (default: where they came from). It writes a `stock_in` reason `returned` linked to the project (needs `inventory.use`). `422` when the project never took eggs from stock or more is returned than taken.

### `POST /sales` and `POST /purchases` — output-named stock lines

A stock line names its stock with **exactly one** of `inventory_item_id` (always supported) or `output: "eggs" | "milk"` (both or neither → `422`; `output` on a non-stock line → `422`).

* **Purchase** (`purchase.create`): the farm's output item is resolved — or created — exactly as `stock-in {output}` does; `storage_location_id` is optional (only active store; none → "Main Store" is created; several → `422 items.N.storage_location_id`). One `purchase` stock-in per line + the expense; identical retries replay the same purchase.
* **Sale** (`sale.create`): the EXISTING output item only. A sale never creates the item, a store or stock: a farm that never stocked the output gets `409 insufficient_stock` (`details.available` = 0) and nothing is written. `storage_location_id` optional (only active store; several → `422`). Insufficient stock is the normal `409`.
* The resolved `inventory_item_id` / `storage_location_id` appear on the saved lines; the request hash for idempotency is computed over what the client sent.

### `POST /sales` — feed is sellable; `/sales` stays authoritative

Sale stock lines accept items whose category is in `sellable_categories` (`produce`, `feed`). Feed is **not** relabelled as produce. A sale writes one `sale` stock-out per line (linked to the sale line), a cancelled sale appends the reversing movement, permissions are unchanged (`sale.create`/`sale.cancel`). Medicine, seed/planting material, agrochemicals and general supplies are `422` on the line. A sale whose stock lines are not all produce is booked to `other_income` (produce-only: `crop_sales`, as before). `reason: sale` on the manual stock-out stays system-only.

## 5. Atomicity, idempotency, permissions

* Every composite action is a single DB transaction under the lock order **farm → cycle → item**. A failure at any step (unknown store, insufficient stock, conversion missing…) leaves **no** record, project, item, store or movement behind.
* Idempotency: records, sales, breeding projects and manual movements each carry their own `idempotency_key` (farm-wide, with a request hash). A retry replays the original and writes nothing new; a different payload is `409 idempotency_conflict`.
* Permissions are never bypassed by a composite endpoint: `egg_collection`/`milk` need `record.create` + `inventory.use`; incubation consumption needs `breeding.create` + `inventory.use`; returned eggs need `inventory.use`; manual IN needs `inventory.manage`; a sale needs `sale.create`. A `vet` can start a plain incubation project but cannot consume stock; `finance` can sell but not take eggs out.
* Tenancy: items, stores, cycles and projects are always resolved through the caller's farm; foreign ids are `404`, and an `output` item/store is never shared between farms.

## 6. Corrections (nothing is silently edited)

* The ledger is append-only. Fix a count with `POST /inventory/adjustments`; fix a record with `POST /records/{id}/reverse`; fix a sale with `POST /sales/{id}/cancel`; fix an incubation by editing/cancelling the project with an explicit return.
* Cross-module reversals stay consistent: reversing an egg record while its eggs were sold is refused (409) rather than leaving negative stock.

## 7. Errors

`409 insufficient_stock` · `409 item_inactive` · `409 idempotency_conflict` · `409 reverse_via_record|reverse_via_sale|reverse_via_purchase` · `422 validation_failed` (system-owned reason, `production` for produce, missing store choice with several stores, more eggs returned than taken, crate without conversion → `422 conversion_not_configured`) · `403 forbidden` · `404 not_found`.

## 8. Master data — `produces_milk`

`produces_milk` is the behavioural authority; nothing in the application branches on a species name. The platform default seeds it (insert-only, via `MasterDataSeeder`, `LivestockCatalogueSeeder` and the data migration `2026_10_18_100000_add_output_stock_support`, which never overwrites a platform edit) for **cattle, goat, sheep, camel, water buffalo**. Horse and donkey are intentionally not seeded for V1; a platform admin can enable the capability for any species later and milk records follow immediately.

## 9. Compatibility and known limitations

* **Behaviour change:** every new `egg_collection` / `milk` record now moves stock (and needs `inventory.use`). Roles with `record.create` but not `inventory.use` get `403` on these types.
* **No backfill.** Records made before this change have no movements, so a farm starts at zero. Seed the real figure once with `POST /inventory/stock-in { output, reason: opening_balance }`. Reversing an old record without a movement is a no-op for stock.
* **Package conversion errors:** a crate without any conversion is now `conversion_not_configured` (previously `conversion_context_required`, because there is now always a default context — the Eggs/Milk item).
* **Legacy `use` on feed is closed.** `POST /inventory/stock-out` with `reason: use` on a feed item is `422` (it would double-decrement a bag already used through `feed_use`). `use` still works for every other category; existing `use` movements on feed stay valid history.
* `feed_use` movements now carry reason `production_use` (was `use`); the input-consumption report counts both.
* Incubation: `start_date` edits do not move the original consumption movement; `eggs_set` edits are reconciled as described above.
* Eggs/milk are single-unit items (`piece` / `l`); they are not lot- or expiry-tracked.
