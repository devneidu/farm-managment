# Phase 4 --- Operations and capability metadata

## Objective

Make dynamic forms/configuration data-driven.

## Backend scope

Operation types, species, breeds, custom breeds, crop types,
capabilities, species capabilities, record types/schema metadata,
reference lists. Seed only reviewed defaults.

## API/UI contract

`/master/farm-operations`, species/capabilities/breeds, crops,
record-types/schema, custom breeds.

## Critical business rules

Changing species can change visible fields/statuses via returned
schema/capabilities. Do not encode biological rules only in Vue
components.

## Tests / acceptance

Capability responses; custom breed farm scope; disabled operation
filtering; schema versioning compatibility.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
