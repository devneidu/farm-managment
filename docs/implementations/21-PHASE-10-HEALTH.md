# Phase 10 --- Health and medicine

## Objective

Support livestock health and medicine inventory without pretending to
diagnose.

## Backend scope

Vaccination, medication, deworming, treatment, disease/issue, vet
visit/follow-up. Multiple medicines per health record with dose.
Medicine packaging/primary unit conversion and withdrawal metadata.

## API/UI contract

`/health-records`, medicine inventory endpoints.

## Critical business rules

Health types drive labels/fields. Medicine use reduces linked stock.
Follow-up may create task. Crop health can use crop-specific
issue/treatment records.

## Tests / acceptance

Multi-medicine doses; stock effects; withdrawal date; farm-vet
permission preset; no cross-farm medicine.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
