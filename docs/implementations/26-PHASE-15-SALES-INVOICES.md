# Phase 15 --- Sales, invoices and payments

## Objective

Implement the selling flow while keeping sale, invoice and payment
distinct.

## Backend scope

Sales/items, inventory/population effects, invoices/items, PDF
generation, payments, balances/status.

## API/UI contract

Sales/invoice/payment endpoints.

## Critical business rules

Sale is operational/commercial event. Invoice is customer document.
Payment settles invoice/receivable. `Save & Create Invoice` is a
convenience workflow, not domain conflation.

## Tests / acceptance

Livestock sale reduces population; egg/produce sale reduces stock;
partial payment; invoice PDF; cancellation/reversal policy.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

## Addendum --- Record payment directly on a sale

`POST /sales/{sale}/payments { amount, method, received_on, idempotency_key }`
(permission `payment.create`, the same as `POST /invoices/{invoice}/payments`).
In one transaction the sale's live invoice is issued if missing, then the
payment goes through `PaymentService` unchanged: amount \> 0 and \<= remaining
balance (409 `payment_exceeds_balance`), one income entry booked, idempotent
retry. Returns the sale with `payments[]`, `payment_status` and `invoice_id`.
The existing invoice endpoints are unchanged. A cancelled sale is 409
`sale_cancelled`.

Frontend rules (see `docs/api/FRONTEND-SALES-FLOW.md`): the UI never asks
about invoices; the Sell form uses the payment block (paid now / part / not
yet); the sale details page offers *Record payment* (this endpoint) and
*Print / Share invoice* (create the invoice if missing, then the PDF); store
lines need no batch (a stock line's `production_cycle_id` is only an optional
allocation, a livestock line's is required because its head count comes from
it); `GET /sales/sellables` is the preferred picker.
