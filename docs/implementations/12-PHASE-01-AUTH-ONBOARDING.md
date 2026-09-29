# Phase 1 --- Auth, tenancy and onboarding

## Objective

Implement the exact simple signup and mandatory farm setup flow.

## Backend scope

`users`, OTPs, social accounts, hidden tenant, farm, initial owner
membership. Email signup requires phone/password confirmation and email
OTP. Google skips app OTP. Create tenant safely; after authentication
expose `requires_farm_setup`. Farm setup creates Farm Name + selected
Farm Operations and defaults Nigeria/NGN/Africa-Lagos/English.

## API/UI contract

Use auth/onboarding endpoints from API contract. Frontend routes
`/register`, `/verify-email`, `/login`, `/onboarding/farm`.

## Critical business rules

Farm setup cannot be dismissed/bypassed by URL or API. Crop-only,
livestock-only and mixed farms valid. No
organization/country/currency/timezone/logo/address questions in first
setup.

## Tests / acceptance

Duplicate email; expired/wrong OTP; resend limits; Google first login;
onboarding gate; at least one operation; tenant isolation.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
