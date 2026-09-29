# Phase 9 --- Inventory, feed and farm inputs

## Objective

Make all stock explainable from movements.

## Backend scope

Inventory items/lots/stores/movements, stock in/out/adjustments, feed
formulas distinct from feed stock, low-stock thresholds.

## API/UI contract

Inventory and feed endpoints.

## Critical business rules

No unexplained editable balance. Feed use can consume stock
transactionally. Farm inputs and medicine share movement infrastructure
but retain domain metadata.

## Tests / acceptance

Concurrent stock out; negative-stock policy; transfer;
purchase/use/adjustment; feed formula does not itself equal stock.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
