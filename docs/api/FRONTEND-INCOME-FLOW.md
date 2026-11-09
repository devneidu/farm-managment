# Frontend flow — Income (sales merged into money in)

There is **no Sales page**. Selling and getting paid live in the **Income** tab. All examples below are real responses from the running code (ids shortened where noted). Money is always a string with two decimals. Send `idempotency_key` on every write.

## 1. Income tab layout

```
┌ Income ───────────────────────────────────────────────┐
│ Money received ₦19,000.00        Still owed ₦28,000.00 │  <- meta.totals (finance.view only)
│ [ Sold something ]   [ Other money in ]                │
│ filters: status · dates · buyer · search · category    │
│ rows …                                                 │
└────────────────────────────────────────────────────────┘
```

- **Money received** = `meta.totals.money_received` for the chosen `from`/`to` (the same figure as `GET /finance/summary` → `totals.income` for that range).
- **Still owed** = `meta.totals.still_owed` — open balances of all non-cancelled sales, un-invoiced ones included, as of now (it ignores the date filter).
- **Sold something** → sale form (`POST /sales`, `GET /sales/sellables` for the store picker). Needs `sale.create`.
- **Other money in** → `POST /income` (categories from `GET /finance/categories` with `manual_entry: true`; egg/milk/crop/livestock sales are sales, not manual income). Needs `finance.create`.
- Hide the totals when `meta.totals` is `null`.

## 2. The list — `GET /finance/income?per_page=10`

```json
{
  "data": [
    { "kind": "other_income", "id": "01a1bc30-9fba-…", "reference": "FIN-2026-00002", "date": "2026-10-14",
      "contact_id": null, "contact_name": null, "contact_phone": null,
      "summary": "Ploughing for a neighbour",
      "category": { "id": "…", "code": "services", "name": "Services" },
      "total": "7000.00", "amount_received": "7000.00", "balance": "0.00", "money_status": "received",
      "links": { "sale": null, "transaction": "/api/v1/finance/transactions/01a1bc30-9fba-…" } },
    { "kind": "sale", "id": "01a1bc30-9d5c-…", "reference": "SAL-2026-00001", "date": "2026-10-12",
      "contact_id": "01a1bc30-9d41-…", "contact_name": "Mama Put Restaurant", "contact_phone": "08031112222",
      "summary": "Eggs (crates) × 10, Delivery",
      "category": { "id": "…", "code": "other_income", "name": "Other income" },
      "total": "32000.00", "amount_received": "12000.00", "balance": "20000.00", "money_status": "part_paid",
      "links": { "sale": "/api/v1/sales/01a1bc30-9d5c-…", "transaction": null } }
  ],
  "meta": { "current_page": 1, "per_page": 10, "last_page": 1, "total": 3,
            "totals": { "currency": "NGN", "from": null, "to": null, "money_received": "19000.00", "still_owed": "28000.00" } },
  "message": null
}
```

One row per sale (never per payment) plus other-income rows; reversed entries are hidden. Sorted newest `date` first, then newest created.

**Badges** (`money_status`): `paid` (green), `part_paid` (amber, show `balance`), `not_paid` (red, show `balance`), `received` (green, other income), `cancelled` (grey; only with `include_cancelled=1`). Open a row by `kind`: `sale` → `GET /sales/{id}`; `other_income` → `GET /finance/transactions/{id}` (reversible there, see `can_reverse_directly`).

## 3. Filters (all optional query params)

| Param | Meaning |
|---|---|
| `money_status` | `paid`, `part_paid`, `not_paid` (sales) or `received` (other income only) |
| `from`, `to` | `YYYY-MM-DD`, inclusive, farm-local |
| `contact_id` | the buyer / contact |
| `search` | reference, buyer name or buyer phone |
| `finance_category_id` | income category |
| `production_cycle_id` | sales with a line in that batch/cycle, or ledger rows allocated to it |
| `include_cancelled` | `1` to show cancelled sales (default `0`) |
| `page`, `per_page` | `per_page` ≤ 100 |

"Customers who owe me" = `?money_status=not_paid` and `?money_status=part_paid`.

## 4. Sold something — `POST /sales`

Farm **not** selling from the store (`settings.use_stock_for_usage` = `false` in `GET /farm`): the user types what was sold.

```json
{ "recorded_at": "2026-10-12T10:00:00Z", "idempotency_key": "sale-demo-1",
  "buyer": { "name": "Mama Put Restaurant", "phone": "08031112222" }, "notes": "Deliver Friday",
  "items": [
    { "kind": "other", "product_type": "eggs", "description": "Eggs (crates)", "quantity": "10", "unit_price": "3000.00" },
    { "kind": "other", "description": "Delivery", "amount": "2000.00" } ] }
```

- `product_type`: `eggs | milk | meat | crops | other_produce`; only on `kind: other`. No stock moves. The income category follows it (eggs → egg_sales, milk → milk_sales, meat → livestock_sales, crops/other_produce → crop_sales). Different typed products together, or a typed line next to a plain one, follow the existing mixed rule.
- A plain "something else" line has no `product_type`.
- Animals are always a `livestock` line from a batch.

Farm **selling from the store** (`use_stock_for_usage` = `true`): show the store picker (`GET /sales/sellables`) and send `stock` lines (`inventory_item_id` or `output`). Do not offer the product type; if sent anyway:

```json
HTTP 422
{ "message": "Your farm sells from the store. Pick the item from your store.", "code": "validation_failed",
  "errors": { "items.0.product_type": ["Your farm sells from the store. Pick the item from your store."] } }
```

The 201 response always carries the money picture, even before any payment:

```json
{ "data": { "id": "01a1bc30-9d5c-…", "reference": "SAL-2026-00001", "status": "active",
  "total_amount": "32000.00", "total": "32000.00", "amount_received": "0.00", "balance": "32000.00",
  "money_status": "not_paid", "payment_status": "uninvoiced", "invoice_id": null, "payments": [],
  "items": [
    { "line_no": 1, "kind": "other", "description": "Eggs (crates)", "product_type": "eggs", "entered_quantity": "10", "amount": "30000.00", "inventory_item_id": null },
    { "line_no": 2, "kind": "other", "description": "Delivery", "product_type": null, "entered_quantity": null, "amount": "2000.00", "inventory_item_id": null } ] },
  "message": "Sale recorded." }
```

Paid now: add `"payment": { "amount": "…", "method": "cash", "received_on": "YYYY-MM-DD" }` (no `payment.create` needed).

## 5. Record payment — `POST /sales/{id}/payments`

Needs `payment.create` (owner, manager, finance). Never ask about invoices; one is created behind the scenes.

```json
{ "amount": "12000.00", "method": "cash", "received_on": "2026-10-13", "idempotency_key": "pay-demo-1" }
```

→ 201 with the sale: `"amount_received": "12000.00", "balance": "20000.00", "money_status": "part_paid", "payments": [{ "reference": "PAY-2026-00001", "amount": "12000.00", "method": "cash", "received_on": "2026-10-13" }]`. Refetch the Income list (both totals change).

## 6. Sale details — `GET /sales/{id}`

Show reference, buyer, lines (with `product_type`), `total / amount_received / balance`, the badge from `money_status`, and `payments[]`. Actions: *Record payment* (balance > 0, `payment.create`), *Invoice* (`invoice.create`: `POST /sales/{id}/invoice`, then the PDF), *Cancel* (`sale.cancel`; with live payments send `reverse_payments: true`, which also needs `payment.reverse`).

## 7. What a Farm Worker sees

`sale.view` + `sale.create` only. `GET /finance/income` returns **sale rows only** and `meta.totals` is `null`: no other income, no money totals. Hide the totals and the "Other money in" button. Real response (trimmed):

```json
{ "data": [ { "kind": "sale", "reference": "SAL-2026-00002", "total": "8000.00", "balance": "8000.00", "money_status": "not_paid" } ],
  "meta": { "current_page": 1, "per_page": 50, "last_page": 1, "total": 2, "totals": null } }
```

Workers cannot record payments or open the transactions list. A member with neither `finance.view` nor `sale.view` (e.g. Vet) gets 403.

## 8. Errors

| When | Response |
|---|---|
| Not signed in | 401 `unauthenticated` |
| No `finance.view` and no `sale.view` | 403 `forbidden` |
| Bad filter (`money_status=x`, `to` before `from`, `per_page>100`) | 422 `validation_failed`, `errors` keyed by param |
| Typed product on a store-selling farm | 422 on `items.N.product_type` (section 4) |
| `product_type` on a stock/livestock line | 422 on `items.N.product_type` |
| Payment above the balance | 409 `payment_exceeds_balance`, real `details`: `{"outstanding":"8000.00","amount":"9000.00"}` |
| Payment on a cancelled sale | 409 `sale_cancelled` |
| Same idempotency key, different body | 409 `idempotency_conflict` |
| Another farm's ids | 404 `not_found` |
