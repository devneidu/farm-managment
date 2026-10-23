# Transactions page — frontend flow

For the Income & Expense **Transactions** page. Everything here is implemented; the examples are real responses captured from the running code (ids shortened where it helps reading). Money is always a **string with two decimals** — never a JS float. Contract detail lives in [PHASE-14-FINANCE.md](PHASE-14-FINANCE.md); sales in [PHASE-15-SALES-INVOICES.md](PHASE-15-SALES-INVOICES.md).

Permissions: list/read need `finance.view`; creating needs `finance.create`; reversing/correcting needs `finance.reverse`. Owner, Manager and Finance have them; Farm Worker and Vet get `403` — hide the page for them.

## 1. The list

`GET /finance/transactions` — newest `occurred_on` first, paginated (`page`, `per_page` ≤ 100, `meta.total`).

| Filter | Values |
|---|---|
| `direction` | `income` · `expense` |
| `payment_method` | `cash` · `bank_transfer` · `pos` · `mobile_money` · `cheque` · `other` |
| `finance_category_id`, `contact_id`, `production_cycle_id` | uuid |
| `from`, `to` | `YYYY-MM-DD`, inclusive, on `occurred_on` |
| `entry_type` | `entry` · `reversal` (use `entry` to hide the offsetting rows) |
| `source_type`, `source_id` | `operational_record` · `health_record` · `purchase` · `payment` |

Reversal rows are in the list by default. A reversed entry has `is_reversed: true`; its offsetting row has `entry_type: "reversal"` and `reverses_transaction_id`. Totals (`GET /finance/summary`) already net them.

A row (from `GET /finance/transactions?payment_method=cash&per_page=5`):

```json
{
  "id": "01a164f1-b58f-73ab-9801-473703790e8a",
  "reference": "FIN-2026-00005",
  "entry_type": "entry",
  "direction": "income",
  "category": { "id": "…", "code": "crop_sales", "name": "Crop & produce sales" },
  "amount": "1000.00",
  "currency": "NGN",
  "occurred_on": "2026-10-14",
  "contact_id": "01a164f1-b21c-715c-b52a-02918f4dc466",
  "contact_name": "Egg Buyer bSi4",
  "production_cycle_id": null,
  "description": "Payment PAY-2026-00001 for INV-2026-00001",
  "payment_method": "cash",
  "quantity": null,
  "unit_price": null,
  "source": {
    "type": "payment",
    "id": "01a164f1-b57f-7214-9309-a87811e6189b",
    "label": "Payment for Sale #1",
    "reference": "PAY-2026-00001",
    "invoice_id": "01a164f1-b4a5-7203-b160-11e8c1191e44",
    "sale_id": "01a164f1-b324-7333-a404-d7841cc2674c"
  },
  "can_reverse_directly": false,
  "is_reversed": false,
  "reversed_by_transaction_id": null
}
```

### Source labels and opening the source

`source` is **always an object**. Show `source.label` in the "Source" column.

| `source.type` | `label` | Extra ids | Open |
|---|---|---|---|
| `null` | `Entered manually` | – | nothing to open |
| `payment` | `Payment for Sale #24` | `reference`, `invoice_id`, `sale_id` | the sale (`GET /sales/{sale_id}`) or invoice (`GET /invoices/{invoice_id}`) |
| `purchase` | `Purchase #8` | `reference` | `GET /purchases/{id}` |
| `health_record` | `Health record` | – | `GET /health-records/{id}` |
| `operational_record` | `Farm record` | – | `GET /records/{id}` |

Income from a customer payment and expenses from a purchase are **created by those modules** — never re-enter them on this page.

### Row actions: `can_reverse_directly`

* `true` → show **Reverse** / **Correct** (section 3).
* `false` → hide them and show **Edit from source** linking to `source` instead. That is the case for payment and purchase rows (reverse the payment / cancel the purchase there), and also for reversal rows and rows that are already reversed.

## 2. The Income / Expense form

Open it from **Add income** / **Add expense**. Submit to `POST /expenses` or `POST /income` (direction pinned) — or `POST /finance/transactions` with `direction`. Always send a fresh `idempotency_key` (uuid) per form submit; reuse it only for a retry of the same submit.

**Fields**

| Field | Notes |
|---|---|
| Category (`finance_category_id`, required) | `GET /finance/categories?direction=income\|expense`. **Only list categories with `manual_entry: true`.** |
| Amount (`amount`) | Required unless quantity and unit price are both given. |
| Quantity × unit price (`quantity`, `unit_price`) | Optional. Send **both or neither**. When both are sent, leave `amount` out — it is computed (`quantity × unit_price`, rounded to 2 decimals). Show the product live in the form for convenience; the server value wins. |
| Date (`occurred_on`, required) | Farm-local day, not in the future. |
| Payment method (`payment_method`) | Optional: `cash`, `bank_transfer`, `pos`, `mobile_money`, `cheque`, `other`. |
| Contact (`contact_id`) | Label it **"Paid to"** for expenses and **"Received from"** for income. Pick with `GET /contacts?role=supplier\|customer`; offer **Create contact** inline with `POST /contacts {name, kind, roles:["supplier"]}` (or `["customer"]`) and use the new id. |
| Batch (`production_cycle_id`) | Optional production cycle (batch/crop) to count the money toward: `GET /production-cycles?status=active`. Leave empty for farm overhead. A closed cycle → `409 cycle_closed`. |
| Description (`description`) | Optional, ≤ 500 characters. |
| Receipt | **Not available yet** (no attachment storage for transactions). Do not show an upload control. |

**Categories** — `GET /finance/categories?direction=income` (excerpt):

```json
{ "id": "…", "code": "egg_sales", "name": "Egg sales", "direction": "income", "is_system": true, "manual_entry": false }
```

Income categories you will see: livestock/egg/milk/crop sales (`manual_entry: false`), `other_income`, **`services`** ("Services") and **`grants_loans_support`** ("Grants, loans & support"). Expense categories are all `manual_entry: true`.

**Example — expense with quantity × unit price** (amount computed to `105000.00`):

```http
POST /api/v1/expenses
{
  "finance_category_id": "01a164f0-88d6-737b-a530-4ffe216603b7",
  "quantity": "12.5", "unit_price": "8400.00",
  "payment_method": "bank_transfer",
  "occurred_on": "2026-10-14",
  "contact_id": "01a164f1-a9e9-7191-acb6-e439ec0b19d2",
  "description": "12.5 bags of layer mash",
  "idempotency_key": "exp-feed-1"
}
```

`201` → `data`: `amount: "105000.00"`, `quantity: "12.5"`, `unit_price: "8400.00"`, `payment_method: "bank_transfer"`, `source: {type: null, id: null, label: "Entered manually"}`, `can_reverse_directly: true`, `message: "Expense recorded."`.

**Example — income, services:**

```http
POST /api/v1/income
{ "finance_category_id": "01a164f0-8a3d-7308-b1bf-40778f6e7168", "amount": "25000.00", "payment_method": "mobile_money",
  "occurred_on": "2026-10-14", "description": "Hatching service for a neighbour", "idempotency_key": "inc-svc-1" }
```

`201`, `message: "Income recorded."`, category `services`.

### "Sold something from your farm?" (Income)

On the Income form show a prominent option **"Sold something from your farm?"** (eggs, milk, crops, livestock, other produce). For now it **opens the existing Sales create flow** (`POST /sales`, then the invoice and payment flow in [PHASE-15-SALES-INVOICES.md](PHASE-15-SALES-INVOICES.md)); stock and income are then booked correctly when the customer pays. A combined single-form flow is coming in the Sales feature — keep this as a simple link/redirect until then. Never offer the four sales categories in the manual form (the server refuses them).

## 3. Correcting entries

The ledger is append-only: there is no edit and no delete.

* **Manual row (`can_reverse_directly: true`)** — *Reverse*: `POST /finance/transactions/{id}/reverse` with `{ "reason": "Entered twice", "idempotency_key": "…", "occurred_on": "YYYY-MM-DD" }` (`occurred_on` optional, defaults to today). It appends an offsetting row (`entry_type: "reversal"`); the original shows `is_reversed: true`. *Correct* = reverse, then submit a normal form with `corrects_transaction_id` set to the reversed row (same direction and source, once; needs `finance.reverse`). Pre-fill the form from the original.
* **Linked row (`can_reverse_directly: false`)** — **Edit from source**. Payments: reverse the payment (`POST /payments/{id}/reverse`) and re-record it. Purchases: cancel the purchase (`POST /purchases/{id}/cancel`) and re-enter it. Calling the transaction reverse endpoint on them returns `409 reverse_via_payment` / `409 reverse_via_purchase`.

## 4. Error cases to handle

All errors use `{ message, code, request_id, errors?, details? }`.

**422 — blocked category** (product sales categories, via API only if the client ignored `manual_entry`). Show the message and, if useful, link to Sales:

```json
{ "message": "Record this through Sales so your stock stays correct.", "code": "validation_failed",
  "errors": { "finance_category_id": ["Record this through Sales so your stock stays correct."] } }
```

**422 — amount does not match quantity × unit price** (omit `amount` when sending the pair):

```json
{ "message": "The amount must equal quantity x unit price (105000.00), or be left out.", "code": "validation_failed",
  "errors": { "amount": ["The amount must equal quantity x unit price (105000.00), or be left out."] } }
```

**422 — only one of quantity / unit price:**

```json
{ "message": "Send quantity and unit_price together, or neither.", "code": "validation_failed",
  "errors": { "unit_price": ["Send quantity and unit_price together, or neither."] } }
```

**409 — `possible_duplicate`** (expenses only: a live purchase has the same amount, the same day and, if you sent a contact, the same contact). Show a confirm dialog with `details.label` ("Purchase #1 already records this"); on **Record anyway** resend the same body with `"confirm_duplicate": true`:

```json
{ "message": "A purchase with the same amount and date already exists. Send confirm_duplicate: true to record this expense anyway.",
  "code": "possible_duplicate",
  "details": { "purchase_id": "01a164f1-adb3-70d4-927a-83eb99f5d15e", "label": "Purchase #1", "reference": "PUR-2026-00001" } }
```

Offer **Open purchase** (`GET /purchases/{details.purchase_id}`) and **Cancel** alongside. The warning never blocks permanently.

Other codes you may meet: `409 cycle_closed`, `409 category_inactive`, `409 contact_inactive`, `409 finance_already_recorded` (a source already has a transaction), `409 transaction_already_reversed`, `409 idempotency_conflict` (same key, different body), `403 forbidden`, `422` field errors (`occurred_on` in the future, unknown payment method, etc.).
