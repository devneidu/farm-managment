# Phase 19 --- Localization/accessibility

## Objective

Prepare the product for Nigerian language support and inclusive use.

## Backend scope

Translation-key infrastructure, locale preference, translated UI
resources, accessibility fixes.

## API/UI contract

`PATCH /me/preferences` or equivalent locale preference endpoint.

## Critical business rules

English launch; Hausa/Yoruba/Igbo/Pidgin packs only after terminology
review. User-entered farm records are not silently translated.

## Tests / acceptance

Fallback locale; missing key; mobile keyboard/input; accessibility
labels/focus/contrast.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
