# Sales, invoices and payments — Phase 15

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side; **never send `farm_id`** (422). Machine-readable schemas are in `openapi.json`.

## Concepts (kept apart)

| Concept | What it is | Table |
|---|---|---|
| Contact / customer | the Phase 14 contact with the `customer` role (no second customer system) | `contacts` |
| Sale | the commercial/operational **event**: what left the farm, to whom, for how much | `sales`, `sale_items` |
| Invoice | the **customer document** for a sale, snapshotted when issued | `invoices`, `invoice_items` |
| Payment | **money actually received** against an invoice | `payments` |
| Finance transaction | one row of the Phase 14 money ledger | `finance_transactions` |
| Inventory movement | physical stock change (Phase 9) | `inventory_movements` |
| Population movement | livestock ledger change (Phase 8) | `population_movements` |

A sale is **not** an invoice and **not** a payment. Recording a sale writes its physical side effects and nothing else: no invoice, no income. Issuing an invoice writes only the document. Receiving a payment books the income. `Save & Create Invoice` is a convenience (`invoice` block on `POST /sales`) that runs the two steps in one transaction; the two documents stay separate rows with their own lifecycles.

```
POST /sales ──► sale ──► sale_items
                  ├─ kind "stock"     ──► ONE Phase 9 stock_out (reason "sale", sale_id + sale_item_id)
                  ├─ kind "livestock" ──► ONE population-ledger exit (operational record "livestock_sale", population_delta = -n)
                  └─ kind "other"     ──► no physical effect
POST /sales/{sale}/invoice ──► invoice (+ snapshotted lines)             books nothing
POST /invoices/{invoice}/payments ──► payment ──► ONE income row in the money ledger (source: payment)
```

**Cash basis.** Income is booked when money is received, never when a sale or invoice is created, so income can never be counted twice. One real payment produces exactly one ledger row (a unique source key makes a second one impossible).

## Money

Amounts are **strings with at most two decimals** (`"1500.50"`; integers and JSON numbers with ≤2 decimals are accepted), positive, at most 16 integer digits, handled with exact decimal arithmetic (bcmath) and returned with two decimals — never floats. A sale total is the exact sum of its line amounts; an invoice balance is `total − live payments`. Currency is the farm's and is never client-supplied.

## Sales

`POST /sales` (permission `sale.create`).

```json
{
  "contact_id": "…customer uuid…",
  "recorded_at": "2026-10-02T09:30:00+01:00",
  "idempotency_key": "sale-2026-10-02-001",
  "items": [
    { "kind": "stock", "inventory_item_id": "…", "storage_location_id": "…", "lot_id": null,
      "components": [{ "quantity": "3", "unit": "crate" }, { "quantity": "5", "unit": "piece" }], "amount": "9000.00" },
    { "kind": "livestock", "production_cycle_id": "…", "head_count": 12, "description": "Spent layers", "amount": "96000.00" },
    { "kind": "other", "description": "Manure (bags)", "amount": "500.05" }
  ],
  "invoice": { "due_date": "2026-10-16", "notes": "Net 14" }
}
```

* **Customer**: `contact_id` must be an active contact with the `customer` role of this farm (foreign ids 404; not a customer 422; inactive `409 contact_inactive`). Omit it for a walk-in sale; `customer_name` (free text) names a walk-in buyer and may not be combined with `contact_id`. A customer with sales cannot lose the role (`409 contact_in_use`).
* **`stock` lines** sell inventory items in a **sellable category** — `produce` (eggs, milk, harvests) and `feed` (surplus feed sold to another farm or distributor); `GET /master/inventory-options` lists them as `sellable_categories`. Other categories are 422. Feed is never relabelled as produce. One Phase 9 stock-out per line (`reason: "sale"`), quantities in the Phase 5 components format; packages resolve only through the item's own conversion; lot-tracked items require `lot_id`; expired lots cannot be sold (`409 lot_expired`); the location/lot bucket can never go negative, now or in dated history (`409 insufficient_stock` with `details.available/requested`).
* **`livestock` lines** remove animals from a **livestock** cycle (`production_cycle_id`, `head_count` ≥ 1) through the Phase 8 population ledger: an operational record of type `livestock_sale` with a negative `population_delta` and its population movement. Population is never edited; the dated ledger must stay non-negative (`409 insufficient_population`); the cycle must be open (`409 cycle_closed`) and the sale not precede the cycle start. This is a livestock **exit**, not a crop harvest.
* **`other` lines** (`description` required) have no physical effect. `production_cycle_id` is an optional allocation on `stock`/`other` lines.
* **Category**: `finance_category_id` (an *income* category) or derived — livestock-only → `livestock_sales`, produce-only → `crop_sales`, anything else (including feed) → `other_income`. Payments are booked to it unless they override it.
* **Atomic**: any failure (insufficient stock/population, foreign ids, expired lot, closed cycle, invoice validation) rolls back the sale, its lines and all effects.
* **Idempotency**: `idempotency_key` is required and farm-wide. Same key + same payload returns the original sale (201) with no second stock-out, population exit or invoice; a changed payload is `409 idempotency_conflict`. A failed attempt does not consume its key.
* `recorded_at` (event time, explicit offset, not in the future) is separate from `created_at`.
* Response fields of interest: `status` (`active`|`cancelled`), `payment_status` (`uninvoiced`|`unpaid`|`partially_paid`|`paid`, derived from the live invoice), `amount_paid`, `outstanding` (null while uninvoiced), `invoice {id, reference, status}`, and per line `inventory_movement_id` / `operational_record_id` (the effect the line caused) plus `inventory_reversal_movement_id` / `reversal_record_id` after a cancellation.

`GET /sales` filters: `status`, `contact_id`, `payment_status`, `search` (reference/customer), `from`, `to` (inclusive farm-local days), `page`, `per_page`. `GET /sales/{sale}`.

### Cancel a sale — `POST /sales/{sale}/cancel` (`sale.cancel`)

Body: `reason`, `recorded_at` (when the goods/animals came back; between the sale and now), `idempotency_key`. In one transaction: one compensating stock movement per stock line (`reversal`, linked to the sale), one compensating population record per livestock line, the unpaid invoice is voided, and the sale becomes `cancelled`. Nothing is deleted or edited.

Policy:

* **Money is never implied.** While payments are live the cancel is refused: `409 sale_has_payments` (`details.amount_paid`). Reverse the payments first (`POST /payments/{payment}/reverse`), then cancel. Physical and financial reversal are independent, explicit records.
* **Impossible reversals are refused**: `409 cycle_closed` when a livestock cycle was closed (reopen it first). Nothing changes on a refused cancel.
* `409 sale_already_cancelled`; replay with the same key returns the cancelled sale; changed payload `409 idempotency_conflict`.
* The stock-out and population record of a sale cannot be reversed individually: `POST /inventory/movements/{id}/reverse` and `POST /records/{id}/reverse` answer `409 reverse_via_sale`.
* A cancelled sale can be replaced **once** with `corrects_sale_id` on a new `POST /sales` (needs `sale.cancel`; otherwise `409 invalid_correction`).

## Invoices

`POST /sales/{sale}/invoice` or `POST /invoices {sale_id, …}` (permission `invoice.create`). Body (all optional except the key): `issue_date` (farm-local, between the sale day and today, default today), `due_date` (≥ issue date), `notes`, `idempotency_key`.

* The invoice **snapshots** the customer (name, phone, email, address from the contact when issued, or the walk-in name), the seller (farm name), the currency, the total and every line (description and a quantity label such as `"90 piece"`/`"12 head"`). Later contact edits, product renames or price changes never alter an issued invoice.
* **One live invoice per sale** (database unique key): `409 invoice_exists`. A cancelled sale cannot be invoiced (`409 sale_cancelled`).
* `POST /invoices/{invoice}/void` (`invoice.void`; `reason`, `idempotency_key`): the invoice becomes `void` and the sale can be invoiced again. `409 invoice_has_payments` while payments are live (reverse them first); `409 invoice_already_void`.
* Invoice state (`issued`|`void`) is separate from payment state: `payment_status` is `unpaid`|`partially_paid`|`paid`; `is_overdue` is true for an issued, unpaid/partial invoice past `due_date`.
* `GET /invoices` filters: `status`, `payment_status`, `overdue`, `contact_id`, `sale_id`, `search`, `from`, `to`, paging. `GET /invoices/{invoice}`.
* **PDF**: `GET /invoices/{invoice}/pdf` (`invoice.view`) returns `application/pdf` (`Content-Disposition: inline; filename="INV-….pdf"`). It is rendered on demand **from the snapshot** plus the live paid/balance figures, so it always matches what was issued. Cross-farm access is 404.
* Sending/sharing an invoice by email or WhatsApp is not part of this phase (delivery belongs to the notification phases); download the PDF and share it.

## Payments

`POST /invoices/{invoice}/payments` (permission `payment.create`).

```json
{ "amount": "250.10", "method": "bank_transfer", "payment_reference": "TRF-88121", "received_on": "2026-10-02",
  "idempotency_key": "pay-2026-10-02-001", "finance_category_id": null, "notes": null }
```

* `method`: `cash`, `bank_transfer`, `pos`, `mobile_money`, `cheque`, `other`. `received_on` is the farm-local day (not in the future, not before the sale).
* **Partial and multiple payments** are normal. A payment can never push the invoice past its total: `409 payment_exceeds_balance` (`details.outstanding`). A void invoice accepts none (`409 invoice_void`).
* One transaction appends the payment and books **one income** entry in the Phase 14 ledger (`source: {type: "payment", id}`; `finance_transaction_id` on the payment). It uses the sale's income category unless `finance_category_id` (an income category) is sent. The income is allocated to a production cycle only when all sale lines belong to **one open** cycle; otherwise it is unallocated (collecting money after a cycle closed stays possible and never edits a closed cycle).
* `idempotency_key` required and farm-wide: same key + same payload returns the original payment (201) with no second income entry; changed payload `409 idempotency_conflict`.
* `POST /payments/{payment}/reverse` (`payment.reverse`; `reason`, optional `recorded_at`, `idempotency_key`) appends an offsetting payment row (`entry_type: "reversal"`) and an offsetting income row; the invoice balance goes back up. `409 payment_already_reversed`; `409 cycle_closed` when the income was allocated to a cycle that is now closed. Wrong amounts are corrected by reversing and recording a new payment. The income row of a payment cannot be reversed via `POST /finance/transactions/{id}/reverse` (`409 reverse_via_payment`).
* `GET /payments` (filters `invoice_id`, `contact_id`, `method`, `entry_type`, `from`, `to`, paging) and `GET /payments/{payment}` (`payment.view`). Payments also appear in `GET /finance/transactions?source_type=payment`, and `GET /finance/summary` counts them as income (reversals offset).

## Permissions

| Permission | Granted to |
|---|---|
| `sale.view`, `sale.create`, `sale.cancel` | Owner, Manager, Finance |
| `invoice.view`, `invoice.create`, `invoice.void` | Owner, Manager, Finance |
| `payment.view`, `payment.create`, `payment.reverse` | Owner, Manager, Finance |

Farm Worker and Vet have none of them (403). A correction (`corrects_sale_id`) also needs `sale.cancel`; the `invoice` block on `POST /sales` also needs `invoice.create`. Writes share the `finance-write` rate limit (240/hour per user).

## Errors

| Code | When |
|---|---|
| 404 | foreign or unknown sale/invoice/payment/contact/item/location/lot/cycle id (never distinguishable from unknown) |
| 409 `insufficient_stock` / `insufficient_population` | the bucket / dated population would go negative |
| 409 `lot_expired`, `item_inactive`, `contact_inactive`, `cycle_closed` | see above |
| 409 `invoice_exists`, `sale_cancelled`, `invoice_void`, `invoice_already_void`, `invoice_has_payments` | invoice lifecycle |
| 409 `payment_exceeds_balance`, `payment_already_reversed` | payment rules |
| 409 `sale_has_payments`, `sale_already_cancelled`, `invalid_correction` | sale cancellation |
| 409 `reverse_via_sale`, `reverse_via_payment` | reversing a sale/payment effect through the wrong door |
| 409 `idempotency_conflict` | same key, different payload |
| 422 | validation (including `farm_id`, `total_amount`, `status`, `currency`, `reference` sent by the client) |
| 403 | missing permission |
