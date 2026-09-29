# Phase 11 --- Breeding and reproduction

## Objective

Implement capability-driven incubation and pregnancy workflows.

## Backend scope

Breeding projects, parent links, milestones/checks/outcomes, offspring
links. Expected dates from configured/reference periods. Poultry
incubation and pregnancy-based workflows differ.

## API/UI contract

Breeding endpoints.

## Critical business rules

Expected dates are estimates. Changing start date recalculates expected
date until outcome/lock. Successful outcomes may create/link offspring
batch/animals through explicit confirmation.

## Tests / acceptance

Incubation fields appear only when capability applies; pregnancy fields
only where applicable; milestones; actual vs expected; outcome side
effects.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
