# Phase 14 --- Contacts, purchasing and finance

## Objective

Connect operational costs to simple farm bookkeeping.

## Backend scope

Contacts, suppliers/customers, purchases, purchase items, income/expense
categories, finance transactions and optional operational/cycle
allocation.

## API/UI contract

Contacts, purchases, finance, expenses/income endpoints.

## Critical business rules

Operational actions may offer `Record as expense/income`; use one
canonical finance transaction and source link to avoid duplicates.

## Tests / acceptance

Purchase stock + expense transaction; duplicate prevention; cycle
profitability allocation; finance role permissions.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

## Addendum --- Income list

`GET /finance/income`: one row per sale plus manual income rows (reversed ones
hidden), SQL UNION, filters, `meta.totals.money_received` (ledger, equals
`/finance/summary` income for the range) and `still_owed` (open balances of
non-cancelled sales, un-invoiced included). `finance.view` sees all; `sale.view`
alone sees sale rows only and no totals.
