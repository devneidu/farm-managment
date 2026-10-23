# Harvest / animals leaving a batch — frontend contract

One form records every way animals leave a livestock batch: **Sold, Died, Slaughtered, Culled, Given away, Moved to another batch**. The farmer picks the batch, the number of animals, the date and what happened, fills the few fields for that choice, and taps **Save**. One request writes everything that choice needs (the sale and payment, the meat in the store, the second batch) in one transaction.

All paths are under `/api/v1`. Auth, CSRF and the optional `X-Farm-Id` header are as in [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md). The examples are real responses captured from the running code (Africa/Lagos farm, NGN); the only edit is that the long, unchanged conversion snapshot inside reversal records is shortened. Reference: [PHASE-08-RECORDS.md](PHASE-08-RECORDS.md), [FEED-EGGS-MILK-STOCK.md](FEED-EGGS-MILK-STOCK.md) (meat), [FRONTEND-SALES-FLOW.md](FRONTEND-SALES-FLOW.md) (the sale behind "Sold").

## 1. The form

### 1.1 Common fields (always shown)

| Field | Notes |
|---|---|
| `production_cycle_id` | An **open livestock batch**. Offer the farm's active livestock batches; show `current_population` from `GET /production-cycles` as "N animals now". A closed batch is `409 cycle_closed`. |
| `type` | `sold`, `died`, `slaughtered`, `culled`, `given_away`, `moved` |
| `head_count` | Whole animals, at least 1, never more than the batch holds on that date (`409 insufficient_population`). |
| `recorded_at` | When it happened, ISO-8601 **with offset** (`2026-10-13T10:00:00+01:00` or `...Z`), between the batch start date and now. |
| `notes` | Optional text. |
| `idempotency_key` | Generate it once when the form opens and reuse it on every retry of the same save. 1–80 chars of letters, digits, `. _ : -`. |

Unknown or irrelevant fields are refused (`422`): for example `meat_weight_kg` on a cull. Send only the fields of the chosen type.

### 1.2 Fields shown per type

| Type | Show | Send |
|---|---|---|
| **Sold** | price per animal, buyer (optional), money received (optional) | `unit_price` (required, string, max 2 decimals) · `buyer {name, phone?}` · `payment {amount, method, received_on?}` — exactly the quick-sell blocks of `POST /sales`, see [FRONTEND-SALES-FLOW.md](FRONTEND-SALES-FLOW.md) §1.3–1.4 |
| **Died** | cause (optional text, default "Unknown") | `cause` |
| **Slaughtered** | meat weight (required), live weight (optional), store (only if the farm has several) | `meat_weight_kg` (> 0, up to 3 decimals) · `live_weight_kg` · `storage_location_id` |
| **Culled** | reason (required dropdown) | `reason`: see 1.3 |
| **Given away** | given to (optional text) | `given_to` |
| **Moved to another batch** | destination batch | `destination_cycle_id`: an **open batch of the same species**, not the same batch. Offer the farm's other active batches of that species. |

### 1.3 Cull reasons (dropdown)

| `reason` | Label |
|---|---|
| `poor_production` | Poor production |
| `sick_injured` | Sick or injured |
| `old` | Old |
| `other` | Other (use `notes` for details) |

### 1.4 What each choice does

* **Sold** — creates the sale (with its invoice, payment and income when money was received), exactly like `POST /sales` with one animals line. The response carries the whole `sale`. Needs `sale.create`.
* **Died** — the ordinary mortality record (same rules as `POST /records` type `mortality`).
* **Slaughtered** — head count goes down **and** `meat_weight_kg` is received into the farm's meat item for that species (**"Chicken meat"**, **"Goat meat"**, kg), created on first use. It then shows up under "In store" in the Sell form. Needs `inventory.use` as well.
* **Culled**, **Given away** — head count goes down.
* **Moved** — the first batch goes down and the second goes up by the same number, together or not at all.

### 1.5 Save

`POST /livestock-exits`. The response always has the same shape: `type`, `label`, `production_cycle_id`, **`head_count`** (what the batch holds *now*), `close_batch_suggested`, `event` (the row exactly as the list shows it) and the type extras `sale`, `meat`, `destination` (otherwise `null`).

Sold, with buyer and part payment:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "sold",
    "head_count": 10,
    "recorded_at": "2026-10-13T06:00:00Z",
    "idempotency_key": "exit-sold-001",
    "unit_price": "4500.00",
    "buyer": {
        "name": "Mama Ngozi",
        "phone": "08031234567"
    },
    "payment": {
        "amount": "30000.00",
        "method": "cash"
    }
}
```

Response `201`:

```json
{
    "data": {
        "type": "sold",
        "label": "Sold",
        "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
        "head_count": 110,
        "close_batch_suggested": false,
        "event": {
            "id": "01a16872-7934-7193-9b33-72d9dd0d7a28",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "sold",
            "label": "Sold",
            "head_count": 10,
            "recorded_at": "2026-10-13T06:00:00.000000Z",
            "notes": null,
            "details": {
                "sale_id": "01a16872-78c8-7205-81b2-cadfa2f446f1",
                "sale_reference": "SAL-2026-00001",
                "buyer": "Mama Ngozi",
                "amount": "45000.00"
            },
            "source": {
                "type": "sale",
                "id": "01a16872-78c8-7205-81b2-cadfa2f446f1",
                "reference": "SAL-2026-00001"
            },
            "undo": "cancel_sale",
            "reversed": false
        },
        "meat": null,
        "destination": null,
        "sale": {
            "id": "01a16872-78c8-7205-81b2-cadfa2f446f1",
            "reference": "SAL-2026-00001",
            "status": "active",
            "contact_id": "01a16872-7872-7122-83bb-713bb1c9b315",
            "customer_name": "Mama Ngozi",
            "recorded_at": "2026-10-13T06:00:00.000000Z",
            "total_amount": "45000.00",
            "currency": "NGN",
            "finance_category": {
                "id": "01a16871-8734-728c-8e28-300b56cb9678",
                "code": "livestock_sales",
                "name": "Livestock sales"
            },
            "payment_status": "partially_paid",
            "amount_paid": "30000.00",
            "outstanding": "15000.00",
            "invoice": {
                "id": "01a16872-79df-720e-88e5-cd3ef77aaa1d",
                "reference": "INV-2026-00001",
                "status": "issued"
            },
            "invoice_id": "01a16872-79df-720e-88e5-cd3ef77aaa1d",
            "payments": [
                {
                    "id": "01a16872-7aa7-70d9-8ed4-1475c727f1a6",
                    "reference": "PAY-2026-00001",
                    "amount": "30000.00",
                    "method": "cash",
                    "received_on": "2026-10-13"
                }
            ],
            "notes": null,
            "corrects_sale_id": null,
            "cancelled_at": null,
            "cancel_reason": null,
            "items": [
                {
                    "id": "01a16872-78eb-70d7-b5b6-25566012953f",
                    "line_no": 1,
                    "kind": "livestock",
                    "description": "Livestock - Broiler batch 1",
                    "inventory_item_id": null,
                    "storage_location_id": null,
                    "inventory_lot_id": null,
                    "lot": null,
                    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
                    "head_count": 10,
                    "quantity": null,
                    "measurement": null,
                    "amount": "45000.00",
                    "inventory_movement_id": null,
                    "inventory_reversal_movement_id": null,
                    "operational_record_id": "01a16872-7934-7193-9b33-72d9dd0d7a28",
                    "reversal_record_id": null
                }
            ],
            "created_by": "01a16872-6c04-7174-8dc0-1fd5989a5612",
            "created_at": "2026-10-14T12:00:00.000000Z"
        }
    },
    "meta": [],
    "message": "Recorded."
}
```


Died:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "died",
    "head_count": 3,
    "recorded_at": "2026-10-13T08:00:00Z",
    "idempotency_key": "exit-died-001",
    "cause": "Heat stress"
}
```

Response `201`:

```json
{
    "data": {
        "type": "died",
        "label": "Died",
        "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
        "head_count": 107,
        "close_batch_suggested": false,
        "event": {
            "id": "01a16872-7de7-71dc-9e46-015ade8a0274",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "died",
            "label": "Died",
            "head_count": 3,
            "recorded_at": "2026-10-13T08:00:00.000000Z",
            "notes": null,
            "details": {
                "cause": "Heat stress"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-7de7-71dc-9e46-015ade8a0274",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        "meat": null,
        "destination": null,
        "sale": null
    },
    "meta": [],
    "message": "Recorded."
}
```


Slaughtered (meat into stock):

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "slaughtered",
    "head_count": 12,
    "recorded_at": "2026-10-13T10:00:00Z",
    "idempotency_key": "exit-slaughter-001",
    "meat_weight_kg": "21.5",
    "live_weight_kg": "30"
}
```

Response `201`:

```json
{
    "data": {
        "type": "slaughtered",
        "label": "Slaughtered",
        "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
        "head_count": 95,
        "close_batch_suggested": false,
        "event": {
            "id": "01a16872-8065-7283-98d6-3431044c8f9e",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "slaughtered",
            "label": "Slaughtered",
            "head_count": 12,
            "recorded_at": "2026-10-13T10:00:00.000000Z",
            "notes": null,
            "details": {
                "meat_weight_kg": "21.5",
                "live_weight_kg": "30",
                "meat_item_id": "01a16872-7fce-7371-9db3-cdfc22e350d0",
                "meat_item": "Chicken meat"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-8065-7283-98d6-3431044c8f9e",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        "meat": {
            "inventory_item_id": "01a16872-7fce-7371-9db3-cdfc22e350d0",
            "name": "Chicken meat",
            "quantity_kg": "21.5",
            "storage_location_id": "01a16872-8016-735d-b705-bf5afa54341d"
        },
        "destination": null,
        "sale": null
    },
    "meta": [],
    "message": "Recorded."
}
```


Culled:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "culled",
    "head_count": 4,
    "recorded_at": "2026-10-13T12:00:00Z",
    "idempotency_key": "exit-cull-001",
    "reason": "poor_production",
    "notes": "Slow growers"
}
```

Response `201`:

```json
{
    "data": {
        "type": "culled",
        "label": "Culled",
        "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
        "head_count": 91,
        "close_batch_suggested": false,
        "event": {
            "id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "culled",
            "label": "Culled",
            "head_count": 4,
            "recorded_at": "2026-10-13T12:00:00.000000Z",
            "notes": "Slow growers",
            "details": {
                "reason": "poor_production"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        "meat": null,
        "destination": null,
        "sale": null
    },
    "meta": [],
    "message": "Recorded."
}
```


Given away:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "given_away",
    "head_count": 2,
    "recorded_at": "2026-10-13T14:00:00Z",
    "idempotency_key": "exit-gift-001",
    "given_to": "Pastor Emeka"
}
```

Response `201`:

```json
{
    "data": {
        "type": "given_away",
        "label": "Given away",
        "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
        "head_count": 89,
        "close_batch_suggested": false,
        "event": {
            "id": "01a16872-8500-7024-a71c-c68d2130b40c",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "given_away",
            "label": "Given away",
            "head_count": 2,
            "recorded_at": "2026-10-13T14:00:00.000000Z",
            "notes": null,
            "details": {
                "given_to": "Pastor Emeka"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-8500-7024-a71c-c68d2130b40c",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        "meat": null,
        "destination": null,
        "sale": null
    },
    "meta": [],
    "message": "Recorded."
}
```


Moved to another batch:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "moved",
    "head_count": 15,
    "recorded_at": "2026-10-13T16:00:00Z",
    "idempotency_key": "exit-move-001",
    "destination_cycle_id": "01a16872-72b7-70c9-b8df-7f9d913bf324"
}
```

Response `201`:

```json
{
    "data": {
        "type": "moved",
        "label": "Moved to another batch",
        "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
        "head_count": 74,
        "close_batch_suggested": false,
        "event": {
            "id": "01a16872-873a-7282-8605-1b456e7999ad",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "moved",
            "label": "Moved to another batch",
            "head_count": 15,
            "recorded_at": "2026-10-13T16:00:00.000000Z",
            "notes": null,
            "details": {
                "destination_cycle_id": "01a16872-72b7-70c9-b8df-7f9d913bf324",
                "destination": "Broiler batch 2"
            },
            "source": {
                "type": "production_cycle",
                "id": "01a16872-72b7-70c9-b8df-7f9d913bf324",
                "reference": "BAT-2026-00002"
            },
            "undo": "reverse",
            "reversed": false
        },
        "meat": null,
        "destination": {
            "production_cycle_id": "01a16872-72b7-70c9-b8df-7f9d913bf324",
            "reference": "BAT-2026-00002",
            "head_count": 55
        },
        "sale": null
    },
    "meta": [],
    "message": "Recorded."
}
```


**Retry safety.** Sending the identical request again (same `idempotency_key`, same body) returns the original event (`201`) and records nothing new: the second batch is not raised twice, no second sale, no second meat stock-in.

```text
first  call: data.event.id = 01a16872-873a-7282-8605-1b456e7999ad
second call: data.event.id = 01a16872-873a-7282-8605-1b456e7999ad
```

The same key with a different body — or with a different `type` — is `409 idempotency_conflict`.

### 1.6 "Head count is 0 → close the batch?"

Every successful save returns `head_count` and `close_batch_suggested`. When `close_batch_suggested` is `true` the batch is now empty: show "This batch has no animals left. Close it?" with **Close batch** / **Not now**. **Close batch** calls the existing `POST /production-cycles/{id}/close` (permission `production_cycle.close`; hide the button when the user lacks it). The API never closes a batch by itself. A batch can also be emptied and stay open (more animals may arrive by a move).

Last animals of the goat pen slaughtered:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-75a4-7255-ab1d-030305531cfe",
    "type": "slaughtered",
    "head_count": 8,
    "recorded_at": "2026-10-14T02:00:00Z",
    "idempotency_key": "exit-goat-001",
    "meat_weight_kg": "96"
}
```

Response `201`:

```json
{
    "data": {
        "type": "slaughtered",
        "label": "Slaughtered",
        "production_cycle_id": "01a16872-75a4-7255-ab1d-030305531cfe",
        "head_count": 0,
        "close_batch_suggested": true,
        "event": {
            "id": "01a16872-8e27-706f-bb3d-405222ca4926",
            "production_cycle_id": "01a16872-75a4-7255-ab1d-030305531cfe",
            "type": "slaughtered",
            "label": "Slaughtered",
            "head_count": 8,
            "recorded_at": "2026-10-14T02:00:00.000000Z",
            "notes": null,
            "details": {
                "meat_weight_kg": "96",
                "live_weight_kg": null,
                "meat_item_id": "01a16872-8da8-72c3-9b66-66ed9780069a",
                "meat_item": "Goat meat"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-8e27-706f-bb3d-405222ca4926",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        "meat": {
            "inventory_item_id": "01a16872-8da8-72c3-9b66-66ed9780069a",
            "name": "Goat meat",
            "quantity_kg": "96",
            "storage_location_id": "01a16872-8016-735d-b705-bf5afa54341d"
        },
        "destination": null,
        "sale": null
    },
    "meta": [],
    "message": "Recorded."
}
```


## 2. The list page

`GET /livestock-exits` (permission `record.view`) — one list of everything that left, newest first. It is built from the existing sale, mortality and exit records; nothing is copied, so a death entered through `POST /records` or a sale made through `POST /sales` appears here too.

| Filter | Values |
|---|---|
| `production_cycle_id` | one batch (the "exits of this batch" tab) |
| `type` | `sold`, `died`, `slaughtered`, `culled`, `given_away`, `moved` |
| `recorded_from`, `recorded_to` | `YYYY-MM-DD`, farm-local, inclusive |
| `page`, `per_page` | default 50, max 100 |

Rows hide **reversed events and reversal rows** (an undone cull disappears; a cancelled sale's animals disappear). A move shows only on the batch the animals left, with the destination; the arrival on the other batch is not an exit.

Each row: `id`, `production_cycle_id`, `type`, `label`, `head_count` (positive), `recorded_at`, `notes`, `details` (type specific, below), `source` (where it lives) and `undo`.

| Type | `details` | `source` |
|---|---|---|
| `sold` | `sale_id`, `sale_reference`, `buyer`, `amount` | `{type: "sale", id: <sale id>, reference}` → open the sale |
| `died` | `cause` | `{type: "operational_record", id}` |
| `slaughtered` | `meat_weight_kg`, `live_weight_kg`, `meat_item_id`, `meat_item` | `{type: "operational_record", id}` |
| `culled` | `reason` | `{type: "operational_record", id}` |
| `given_away` | `given_to` | `{type: "operational_record", id}` |
| `moved` | `destination_cycle_id`, `destination` (batch name) | `{type: "production_cycle", id: <destination batch>, reference}` → open the batch |

`undo` is `reverse` (use section 3) or `cancel_sale` (a sold row: use `POST /sales/{id}/cancel`).

`GET /livestock-exits?production_cycle_id=01a16872-6fc7-7140-89f2-ab222208f3e0&per_page=4`

Response `200`:

```json
{
    "data": [
        {
            "id": "01a16872-873a-7282-8605-1b456e7999ad",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "moved",
            "label": "Moved to another batch",
            "head_count": 15,
            "recorded_at": "2026-10-13T16:00:00.000000Z",
            "notes": null,
            "details": {
                "destination_cycle_id": "01a16872-72b7-70c9-b8df-7f9d913bf324",
                "destination": "Broiler batch 2"
            },
            "source": {
                "type": "production_cycle",
                "id": "01a16872-72b7-70c9-b8df-7f9d913bf324",
                "reference": "BAT-2026-00002"
            },
            "undo": "reverse",
            "reversed": false
        },
        {
            "id": "01a16872-8500-7024-a71c-c68d2130b40c",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "given_away",
            "label": "Given away",
            "head_count": 2,
            "recorded_at": "2026-10-13T14:00:00.000000Z",
            "notes": null,
            "details": {
                "given_to": "Pastor Emeka"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-8500-7024-a71c-c68d2130b40c",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        {
            "id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "culled",
            "label": "Culled",
            "head_count": 4,
            "recorded_at": "2026-10-13T12:00:00.000000Z",
            "notes": "Slow growers",
            "details": {
                "reason": "poor_production"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        },
        {
            "id": "01a16872-8065-7283-98d6-3431044c8f9e",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "slaughtered",
            "label": "Slaughtered",
            "head_count": 12,
            "recorded_at": "2026-10-13T10:00:00.000000Z",
            "notes": null,
            "details": {
                "meat_weight_kg": "21.5",
                "live_weight_kg": "30",
                "meat_item_id": "01a16872-7fce-7371-9db3-cdfc22e350d0",
                "meat_item": "Chicken meat"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-8065-7283-98d6-3431044c8f9e",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 4,
        "last_page": 2,
        "total": 6
    },
    "message": null
}
```


Filtered (culls of the last two days):

`GET /livestock-exits?production_cycle_id=01a16872-6fc7-7140-89f2-ab222208f3e0&type=culled&recorded_from=2026-10-13&recorded_to=2026-10-14`

Response `200`:

```json
{
    "data": [
        {
            "id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "culled",
            "label": "Culled",
            "head_count": 4,
            "recorded_at": "2026-10-13T12:00:00.000000Z",
            "notes": "Slow growers",
            "details": {
                "reason": "poor_production"
            },
            "source": {
                "type": "operational_record",
                "id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
                "reference": null
            },
            "undo": "reverse",
            "reversed": false
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 50,
        "last_page": 1,
        "total": 1
    },
    "message": null
}
```


## 3. Undo

`POST /livestock-exits/{record}/reverse` — `{record}` is the row `id`. Permission `record.reverse` (Owner and Manager); hide **Undo** for other roles. Body: `reason` (required), `recorded_at` (not before the event), `idempotency_key`. The response has `reversed_record_id`, the `reversal` record (and `linked_reversal` for a move), and the batch's new `head_count`.

| Row type | What Undo does |
|---|---|
| `died`, `culled`, `given_away` | Reverses the record; the animals are back. |
| `slaughtered` | Reverses the record **and** takes the meat back out of stock. If that meat was already sold or used: `409 insufficient_stock`, nothing changes — tell the user to correct the stock first. |
| `moved` | Reverses **both** batches together. If the receiving batch has since lost those animals: `409 insufficient_population`, nothing changes. |
| `sold` | Refused: `409 reverse_via_sale` (`details.sale_id`). Show "Cancel the sale instead" and open the sale; cancelling it brings the animals back. |

Reversing a record twice is `409 record_already_reversed`. A closed batch must be reopened first (`409 cycle_closed`). `POST /records/{id}/reverse` on either side of a move answers `409 reverse_via_livestock_exit`.

Undo a cull:

`POST /livestock-exits/01a16872-82b6-733a-bdc8-d62b1e9b1604/reverse`

Request:

```json
{
    "reason": "Entered on the wrong batch",
    "recorded_at": "2026-10-14T10:00:00Z",
    "idempotency_key": "undo-cull-001"
}
```

Response `201`:

```json
{
    "data": {
        "reversed_record_id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
        "type": "culled",
        "reversal": {
            "id": "01a16872-a14c-737c-b14f-bf96ae92923f",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "reversal",
            "details": {
                "reason": "Entered on the wrong batch"
            },
            "measurement": {
                "total": {
                    "unit": "head",
                    "quantity": "4"
                },
                "note": "conversion snapshot omitted here; identical to POST /records/{id}/reverse"
            },
            "population_delta": 4,
            "recorded_at": "2026-10-14T10:00:00.000000Z",
            "notes": null,
            "reverses_record_id": "01a16872-82b6-733a-bdc8-d62b1e9b1604",
            "corrects_record_id": null,
            "linked_record_id": null,
            "reversed_by_record_id": null,
            "inventory_movement_id": null,
            "created_by": "01a16872-6c04-7174-8dc0-1fd5989a5612",
            "created_at": "2026-10-14T12:00:00.000000Z",
            "attachments": []
        },
        "linked_reversal": null,
        "head_count": 78,
        "close_batch_suggested": false
    },
    "meta": [],
    "message": "Reversal recorded."
}
```


Undo a move (both batches):

`POST /livestock-exits/01a16872-873a-7282-8605-1b456e7999ad/reverse`

Request:

```json
{
    "reason": "Moved the wrong pen",
    "recorded_at": "2026-10-14T10:00:00Z",
    "idempotency_key": "undo-move-001"
}
```

Response `201`:

```json
{
    "data": {
        "reversed_record_id": "01a16872-873a-7282-8605-1b456e7999ad",
        "type": "moved",
        "reversal": {
            "id": "01a16872-a48e-704e-80b5-df382023b009",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "reversal",
            "details": {
                "reason": "Moved the wrong pen"
            },
            "measurement": {
                "total": {
                    "unit": "head",
                    "quantity": "15"
                },
                "note": "conversion snapshot omitted here; identical to POST /records/{id}/reverse"
            },
            "population_delta": 15,
            "recorded_at": "2026-10-14T10:00:00.000000Z",
            "notes": null,
            "reverses_record_id": "01a16872-873a-7282-8605-1b456e7999ad",
            "corrects_record_id": null,
            "linked_record_id": null,
            "reversed_by_record_id": null,
            "inventory_movement_id": null,
            "created_by": "01a16872-6c04-7174-8dc0-1fd5989a5612",
            "created_at": "2026-10-14T12:00:00.000000Z",
            "attachments": []
        },
        "linked_reversal": {
            "id": "01a16872-a55b-7345-8679-6d1f6fae1198",
            "production_cycle_id": "01a16872-72b7-70c9-b8df-7f9d913bf324",
            "type": "reversal",
            "details": {
                "reason": "Moved the wrong pen"
            },
            "measurement": {
                "total": {
                    "unit": "head",
                    "quantity": "15"
                },
                "note": "conversion snapshot omitted here; identical to POST /records/{id}/reverse"
            },
            "population_delta": -15,
            "recorded_at": "2026-10-14T10:00:00.000000Z",
            "notes": null,
            "reverses_record_id": "01a16872-87ff-71de-bc6e-730347a2d4e8",
            "corrects_record_id": null,
            "linked_record_id": null,
            "reversed_by_record_id": null,
            "inventory_movement_id": null,
            "created_by": "01a16872-6c04-7174-8dc0-1fd5989a5612",
            "created_at": "2026-10-14T12:00:00.000000Z",
            "attachments": []
        },
        "head_count": 93,
        "close_batch_suggested": false
    },
    "meta": [],
    "message": "Reversal recorded."
}
```


Undo a slaughter (the meat leaves stock too):

`POST /livestock-exits/01a16872-8065-7283-98d6-3431044c8f9e/reverse`

Request:

```json
{
    "reason": "Counted twice",
    "recorded_at": "2026-10-14T10:00:00Z",
    "idempotency_key": "undo-slaughter-001"
}
```

Response `201`:

```json
{
    "data": {
        "reversed_record_id": "01a16872-8065-7283-98d6-3431044c8f9e",
        "type": "slaughtered",
        "reversal": {
            "id": "01a16872-a73a-70b4-91d3-a967cd5d8dec",
            "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
            "type": "reversal",
            "details": {
                "reason": "Counted twice"
            },
            "measurement": {
                "total": {
                    "unit": "head",
                    "quantity": "12"
                },
                "note": "conversion snapshot omitted here; identical to POST /records/{id}/reverse"
            },
            "population_delta": 12,
            "recorded_at": "2026-10-14T10:00:00.000000Z",
            "notes": null,
            "reverses_record_id": "01a16872-8065-7283-98d6-3431044c8f9e",
            "corrects_record_id": null,
            "linked_record_id": null,
            "reversed_by_record_id": null,
            "inventory_movement_id": "01a16872-a77b-72a8-a8a8-86498f41cced",
            "created_by": "01a16872-6c04-7174-8dc0-1fd5989a5612",
            "created_at": "2026-10-14T12:00:00.000000Z",
            "attachments": []
        },
        "linked_reversal": null,
        "head_count": 105,
        "close_batch_suggested": false
    },
    "meta": [],
    "message": "Reversal recorded."
}
```


Sold row — refused:

`POST /livestock-exits/01a16872-7934-7193-9b33-72d9dd0d7a28/reverse`

Request:

```json
{
    "reason": "Buyer returned them",
    "recorded_at": "2026-10-14T10:00:00Z",
    "idempotency_key": "undo-sold-001"
}
```

Response `409`:

```json
{
    "message": "This population effect belongs to a sale; cancel the sale instead.",
    "code": "reverse_via_sale",
    "request_id": "01a16872-a873-710f-9409-7ceff00faefe",
    "details": {
        "sale_id": "01a16872-78c8-7205-81b2-cadfa2f446f1"
    }
}
```


Direct reversal of a move through the generic endpoint — refused:

`POST /records/01a16872-873a-7282-8605-1b456e7999ad/reverse`

Request:

```json
{
    "reason": "x",
    "recorded_at": "2026-10-14T10:00:00Z",
    "idempotency_key": "undo-move-002"
}
```

Response `409`:

```json
{
    "message": "This record is one side of a move between batches; undo it with POST /livestock-exits/{record}/reverse so both batches are corrected together.",
    "code": "reverse_via_livestock_exit",
    "request_id": "01a16872-a225-73bd-8fc2-776ffee93b1b"
}
```


A farm worker cannot undo:

`POST /livestock-exits/01a16872-873a-7282-8605-1b456e7999ad/reverse`

Request:

```json
{
    "reason": "x",
    "recorded_at": "2026-10-14T11:00:00Z",
    "idempotency_key": "undo-w-001"
}
```

Response `403`:

```json
{
    "message": "You are not allowed to perform this action.",
    "code": "forbidden",
    "request_id": "01a16872-a937-7006-b9bb-63dc7e0ec014"
}
```


## 4. Errors

| Status / `code` | When | Show |
|---|---|---|
| `422 validation_failed` | missing or irrelevant field, bad reason, same batch or other species as destination | field errors under `errors.<field>` |
| `409 insufficient_population` | more animals than the batch holds on that date | "The batch only has N animals" |
| `409 cycle_closed` | the batch (or destination) is closed; `details.production_cycle_id` says which | offer to reopen |
| `409 idempotency_conflict` | same key, different body or type | regenerate the key, re-read the form |
| `409 insufficient_stock` | undo of a slaughter whose meat was used | see section 3 |
| `409 reverse_via_sale` / `reverse_via_livestock_exit` | wrong undo path | open the sale / use the exits undo |
| `403 forbidden` | missing permission for this type | hide the type for roles without it |
| `404 not_found` | batch, destination or row not in this farm | – |

Validation (a cull without a reason):

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "culled",
    "head_count": 1,
    "recorded_at": "2026-10-14T07:00:00Z",
    "idempotency_key": "exit-bad-001"
}
```

Response `422`:

```json
{
    "message": "The reason field is required when type is culled.",
    "code": "validation_failed",
    "request_id": "01a16872-8f59-700d-85f7-eaea12792973",
    "errors": {
        "reason": [
            "The reason field is required when type is culled."
        ]
    }
}
```


Not enough animals:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "culled",
    "head_count": 500,
    "recorded_at": "2026-10-14T07:00:00Z",
    "idempotency_key": "exit-bad-002",
    "reason": "old"
}
```

Response `409`:

```json
{
    "message": "This event would produce a negative population, including in the dated ledger history.",
    "code": "insufficient_population",
    "request_id": "01a16872-90f9-727a-b4a7-4128f0c02459"
}
```


Destination of another species:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "moved",
    "head_count": 1,
    "recorded_at": "2026-10-14T07:00:00Z",
    "idempotency_key": "exit-bad-003",
    "destination_cycle_id": "01a16872-75a4-7255-ab1d-030305531cfe"
}
```

Response `422`:

```json
{
    "message": "Animals can only be moved to a batch of the same species (Chicken).",
    "code": "validation_failed",
    "request_id": "01a16872-9607-7052-85af-bca9487385c2",
    "errors": {
        "destination_cycle_id": [
            "Animals can only be moved to a batch of the same species (Chicken)."
        ]
    }
}
```


Same key, different body:

`POST /livestock-exits`

Request:

```json
{
    "production_cycle_id": "01a16872-6fc7-7140-89f2-ab222208f3e0",
    "type": "moved",
    "head_count": 16,
    "recorded_at": "2026-10-13T16:00:00Z",
    "idempotency_key": "exit-move-001",
    "destination_cycle_id": "01a16872-72b7-70c9-b8df-7f9d913bf324"
}
```

Response `409`:

```json
{
    "message": "This idempotency key was already used for a different request.",
    "code": "idempotency_conflict",
    "request_id": "01a16872-978f-70da-b7e5-424a0eb84ef3"
}
```


## 5. Permissions

| Action | Permission |
|---|---|
| Record died, culled, given away, moved, slaughtered | `record.create` (Owner, Manager, Farm Worker) |
| Record sold | `sale.create` (Owner, Manager, Finance, Farm Worker) |
| Record slaughtered | also `inventory.use` |
| List | `record.view` |
| Undo | `record.reverse` (Owner, Manager) |

A Vet cannot record exits. Meat sold later goes through the Sell form like any produce.
