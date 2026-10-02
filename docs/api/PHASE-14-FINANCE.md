# Contacts, purchasing and finance — Phase 14

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side; **never send `farm_id`** (422). Machine-readable schemas are in `openapi.json`.

## Concepts (kept apart)

| Concept | What it is | Table |
|---|---|---|
| Contact | a person/business the farm deals with | `contacts` |
| Purchase | a procurement document (lines, total) | `purchases`, `purchase_items` |
| Finance transaction | one row of the money ledger | `finance_transactions` |
| Inventory movement | physical stock change (Phase 9) | `inventory_movements` |
| Operational / health record | what happened on the farm (Phases 8/10) | unchanged |

One real-world action is recorded once; its linked effects are written in the same database transaction. **Money never changes stock and stock never changes money by itself**: a stocked purchase is the one path that writes both, and it does so through the Phase 9 stock-in and the money ledger, never through a balance.

## Money

Amounts are **strings with at most two decimals** (`"1500.50"`; integers and JSON numbers with ≤2 decimals are accepted), positive, at most 16 integer digits. They are added and compared with exact decimal arithmetic (bcmath) — never floats. Responses always return two decimals. The currency is the farm's (`NGN` default) and is never client-supplied. A purchase total is the exact sum of its line amounts (`0.10 + 0.20 + 1250.45 = "1250.75"`).

## Contacts

A contact carries `roles`: `supplier` and/or `customer`. A neighbour who sells feed to the farm *and* buys eggs from it is **one** contact with both roles; names are unique per farm (case/space-insensitive), so a second create answers `409 contact_exists` and the client should add the missing role with `PATCH` instead. Contacts are farm-scoped (foreign ids are 404). Deactivating (`is_active: false`) hides the contact from new documents (`409 contact_inactive`) but keeps history. A supplier with purchases cannot lose the supplier role (`409 contact_in_use`). Purchases require a *supplier*; transactions accept any active contact. Customers are used by Phase 15 (sales) — no sales endpoint exists yet.

## Purchases

`POST /purchases` records one procurement event in a single transaction:

```
purchase ──► purchase_items
               ├─ kind "stock"     ──► ONE Phase 9 stock_in (reason "purchase", purchase_id + purchase_item_id)
               └─ kind "non_stock" ──► no inventory movement (services, transport, rent…)
         └──► ONE expense in the money ledger linked to the purchase (unless record_expense=false)
```

```json
{
  "contact_id": "…supplier uuid…",
  "recorded_at": "2026-10-02T09:30:00+01:00",
  "idempotency_key": "pur-2026-10-02-001",
  "supplier_reference": "INV-7781",
  "production_cycle_id": "…optional cycle the cost belongs to…",
  "items": [
    { "kind": "stock", "inventory_item_id": "…", "storage_location_id": "…",
      "components": [{ "quantity": "2", "unit": "bag" }, { "quantity": "5", "unit": "kg" }],
      "lot": { "code": "L-1", "expires_on": "2027-06-30" }, "amount": "9000.00" },
    { "kind": "non_stock", "description": "Delivery truck", "amount": "1250.45" }
  ]
}
```

* **Quantities** use the Phase 5 components format. Packages (`bag`) resolve **only** through the item's own package conversion (`POST /settings/package-conversions`); without it the request is 422. The stored quantity is the canonical normalised quantity plus the replayable conversion snapshot (`measurement`).
* **Lots / expiry**: items that track lots require `lot_id` or `lot {code, expires_on}` on the line (expiry-tracked items require `expires_on`); a lot already expired on the purchase date is `409 lot_expired`; an existing lot code with a different expiry is 422. Items that do not track lots reject lots.
* **Storage location** must be an active storage location of the farm (foreign/unknown = 404).
* **Category**: `finance_category_id` (an *expense* category) or derived — all stock lines of one inventory category map to the same-named expense category (`feed`, `medicine_veterinary`, `seed_planting_material`, `fertilizer_agrochemical`; `general_supply`/`produce` → `general_supplies`); non-stock lines → `other_expense`; mixed lines → `general_supplies`.
* **Allocation**: `production_cycle_id` (must be an open cycle, `409 cycle_closed` otherwise) is copied onto the expense so it counts toward that cycle's profitability.
* **`record_expense: false`** books no expense; it can be booked later, once, with `POST /expenses {source:{type:"purchase",id}}` for exactly the purchase total (see Duplicate prevention).
* **Atomic**: any failure (insufficient/foreign ids/expired lot/inactive item/closed cycle) rolls back purchase, lines, stock-ins and expense together.
* **Idempotency**: `idempotency_key` is required and farm-wide. The same key + same payload returns the original purchase (201) with no second stock-in or expense; a changed payload is `409 idempotency_conflict`. A failed attempt does not consume its key.
* `recorded_at` (event time, explicit offset, not in the future) is separate from `created_at`; the stock-in movements and the expense carry it.

`GET /purchases` filters: `status` (`active`/`cancelled`), `contact_id`, `production_cycle_id`, `search` (reference or supplier reference), `from`/`to` (farm-local days), `page`, `per_page`. `GET /purchases/{purchase}` returns the lines (`inventory_movement_id` per stock line), `finance_transaction_id` and, after cancellation, `finance_reversal_transaction_id`. Inventory movements expose `purchase_id`/`purchase_item_id` and `GET /inventory/movements?purchase_id=` finds them.

### Cancel / correct

`POST /purchases/{purchase}/cancel {reason, recorded_at, idempotency_key}` (permission `purchase.cancel`) appends one compensating stock movement per stock line and an offsetting ledger row, then marks the purchase `cancelled`. Nothing is edited or deleted. If the received stock was since used or moved the cancel is `409 insufficient_stock` and **nothing** changes. Repeating the same request replays; another key is `409 purchase_already_cancelled`. Correct by cancelling, then `POST /purchases` with `corrects_purchase_id` (once; also needs `purchase.cancel`; `409 invalid_correction` otherwise). The purchase's stock movements cannot be reversed through `/inventory/movements/{id}/reverse` (`409 reverse_via_purchase`) and its expense cannot be reversed through `/finance/transactions/{id}/reverse` (`409 reverse_via_purchase`) — cancel the purchase.

## Finance

`GET /finance/categories[?direction=income|expense]` — platform categories (`is_system: true`). Expense: `feed`, `medicine_veterinary`, `seed_planting_material`, `fertilizer_agrochemical`, `livestock_purchase`, `labour`, `transport`, `utilities`, `equipment_repairs`, `general_supplies`, `other_expense`. Income: `livestock_sales`, `egg_sales`, `milk_sales`, `crop_sales`, `other_income`. Custom farm categories are not built; the table already supports `farm_id`.

`POST /finance/transactions`, `POST /expenses` (direction pinned to expense) and `POST /income` (income):

```json
{
  "direction": "expense", "finance_category_id": "…", "amount": "3500.00",
  "occurred_on": "2026-10-02", "description": "Vet call-out", "contact_id": "…",
  "production_cycle_id": "…", "source": { "type": "operational_record", "id": "…" },
  "idempotency_key": "exp-1"
}
```

* The category must be active and match the direction (422 on `finance_category_id`).
* `occurred_on` is a farm-local day, not in the future; `recorded_at` (optional, defaults to now) is the entry time and is kept apart from `created_at`.
* **Allocation**: `production_cycle_id` allocates to an open cycle (`409 cycle_closed`); unknown/foreign cycle 404. Omit it for farm overhead.

### Record as expense/income — source link & duplicate prevention

`source` = `{type: operational_record | health_record | purchase, id}`. There is **one live transaction per source**: a second attempt is `409 finance_already_recorded` with `details.transaction_id` of the existing one. The first entry for a source also holds a database-level unique key, so a race cannot double-book. A reversed or reversal source is `409 source_reversed`. The cycle is taken from the source (an explicit different cycle is 422). A **purchase** books its own expense, so a manual expense for a purchase is only possible when it was recorded with `record_expense=false`, with exactly its total and as an expense. Tasks never create transactions — a due task is not money.

### Reverse / correct

`POST /finance/transactions/{id}/reverse {reason, occurred_on?, idempotency_key}` (`finance.reverse`) appends a reversal row (same direction, category, amount, contact, cycle and source; `entry_type: "reversal"`, `reverses_transaction_id`). Totals subtract it; the original stays (`is_reversed: true`, `reversed_by_transaction_id`). A reversal cannot be reversed and nothing is reversed twice (`409 transaction_already_reversed`); allocated money in a closed cycle needs the cycle reopened (`409 cycle_closed`). To replace it, `POST` a new transaction with `corrects_transaction_id` (same direction and source, once; needs `finance.reverse`; `409 invalid_correction`). After a reversal the source is free to be booked again. There is no PATCH/DELETE: the ledger is append-only.

### Listing and profitability

`GET /finance/transactions` — filters `direction`, `entry_type`, `finance_category_id`, `contact_id`, `production_cycle_id`, `source_type`, `source_id`, `from`, `to` (inclusive farm-local `occurred_on` days), `page`, `per_page`. Reversal rows are included.

`GET /finance/summary[?from=&to=&production_cycle_id=]` returns signed totals (reversals offset entries):

```json
{ "currency": "NGN", "production_cycle_id": null,
  "totals": { "income": "120000.00", "expense": "57000.49", "net": "62999.51" },
  "by_category": [{ "finance_category_id": "…", "code": "feed", "name": "Feed", "direction": "expense", "amount": "45000.50" }],
  "by_cycle": [{ "production_cycle_id": "…", "income": "120000.00", "expense": "57000.49", "net": "62999.51" },
               { "production_cycle_id": null, "income": "0.00", "expense": "55.05", "net": "-55.05" }] }
```

With `production_cycle_id` the response is that cycle's profitability (no `by_cycle`). `null` cycle = unallocated.

## Endpoints and permissions

| Method | Path | Permission | Success |
|---|---|---|---|
| GET | `/contacts`, `/contacts/{contact}` | `contact.view` | 200 |
| POST | `/contacts` | `contact.manage` | 201 |
| PATCH | `/contacts/{contact}` | `contact.manage` | 200 |
| GET | `/purchases`, `/purchases/{purchase}` | `purchase.view` | 200 |
| POST | `/purchases` | `purchase.create` (+ `purchase.cancel` with `corrects_purchase_id`) | 201 |
| POST | `/purchases/{purchase}/cancel` | `purchase.cancel` | 200 |
| GET | `/finance/categories`, `/finance/summary`, `/finance/transactions`, `/finance/transactions/{id}` | `finance.view` | 200 |
| POST | `/finance/transactions`, `/expenses`, `/income` | `finance.create` (+ `finance.reverse` with `corrects_transaction_id`) | 201 |
| POST | `/finance/transactions/{id}/reverse` | `finance.reverse` | 201 |

Roles: **Owner** all; **Manager** and **Finance** all of the above (the matrix calls the Manager preset "configurable"; per-farm configuration is not built, so the default is full access); **Farm Worker** and **Vet** none (403 on every endpoint). The old reserved `finance.expense.create` was replaced by `finance.create`. Write limiter: `finance-write` 240/h/user. Not subscription-gated.

## Errors

`401` unauthenticated · `403 forbidden` · `404` foreign/unknown ids · `409` `contact_exists`, `contact_in_use`, `contact_inactive`, `category_inactive`, `cycle_closed`, `lot_expired`, `item_inactive`, `insufficient_stock`, `finance_already_recorded`, `source_reversed`, `transaction_already_reversed`, `purchase_already_cancelled`, `reverse_via_purchase`, `invalid_correction`, `idempotency_conflict` · `422` validation (field-keyed, e.g. `items.0.components`, `amount`, `finance_category_id`). Error body: `{message, code, request_id, errors?, details?}`.

## Events

`FinanceTransactionRecorded` (every ledger row) and `PurchaseRecorded` (created/cancelled) are dispatched after commit — hooks for later reporting/notification phases.

## Deliberately not in Phase 14

Payables/receivables and payments (`expense/payable` is Phase 15's invoice/payment domain), sales/invoices, custom farm categories, per-line allocation or cost splitting across several cycles, stock valuation / unit-cost (`FIFO`), taxes/discounts, multi-currency, bank reconciliation, and auto-booking of expenses by operational records (a record only *offers* the explicit `source` link).
