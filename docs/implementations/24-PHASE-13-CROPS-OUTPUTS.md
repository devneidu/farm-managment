# Phase 13 --- Crop operations and outputs

## Objective

Complete crop-specific workflows and unified production outputs.

## Backend scope

Land prep, planting, establishment/survival, fertilizer,
pesticide/herbicide, irrigation, weeding, growth stage, crop loss, crop
harvest; output inventory integration.

## API/UI contract

Record engine schemas plus crop project detail endpoints.

## Critical business rules

Planting units are baseline. Harvest quantity may be compound if
configured. Crop harvest increases produce inventory; livestock exit is
not called crop harvest.

## Tests / acceptance

50→47 survival 94%; loss; harvest stock-in; unit validation;
plot/project scope.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
