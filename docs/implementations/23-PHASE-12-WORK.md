# Phase 12 --- Templates, schedules, tasks and calendar

## Objective

Turn farm knowledge into simple actionable work without automatic fake
records.

## Backend scope

Platform/farm work templates, items, schedules, generated tasks,
reminders, assignments, calendar aggregation, completion evidence links.

## API/UI contract

Work-template, schedule, task and calendar endpoints.

## Critical business rules

Recommend template when new cycle is created. User can
apply/customize/skip. Schedules create tasks; tasks never silently
create mortality/income/medicine quantities.

## Tests / acceptance

Template versioning; recurring generation dedupe; overdue/due-today
states; evidence-linked completion; role assignment.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
