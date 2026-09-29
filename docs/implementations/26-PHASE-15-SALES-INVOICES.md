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
