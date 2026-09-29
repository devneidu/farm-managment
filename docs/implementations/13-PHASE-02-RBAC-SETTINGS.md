# Phase 2 --- RBAC, team and minimal settings

## Objective

Give farms safe collaboration without a complicated settings area.

## Backend scope

Memberships, invitations, roles/permissions, farm profile, enabled
operations, notification preferences shell, locations/config entry
points.

## API/UI contract

`/farm`, `/farm/members`, `/farm/invitations`, `/roles`, `/permissions`,
`/settings/*`.

## Critical business rules

Roles: Farm Admin/Owner, Manager, Finance, Farm Worker; optional Vet
role may be implemented as a role preset granting health/medicine
permissions rather than hard-coded special-case logic. Backend policies
authoritative.

## Tests / acceptance

Invite/accept/revoke; cross-farm denial; worker cannot access finance;
vet preset limited appropriately; owner safety.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.
