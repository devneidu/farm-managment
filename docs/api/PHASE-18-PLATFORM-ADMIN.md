# Platform administration — Phase 18

All paths have prefix `/api/v1/platform-admin`. Same Sanctum SPA session and CSRF set-up as the rest of the API. These endpoints are **not farm endpoints**: there is no `X-Farm-Id`, no farm permission and no farm context. A farm Owner/Manager has **no** access here.

Platform administration manages the SaaS (plans, reference data, work templates, settings, flags, support, audit). It never edits a farm's business records: there is no endpoint for population, stock, finance, sales, health, breeding or tasks.

## Access model

| Piece | Rule |
|---|---|
| Grant | A row in `platform_admins` (one per user). Created **only** by `php artisan platform:grant-admin {email} --role=admin\|support`, removed by `platform:revoke-admin {email}`. No API can create, change or escalate a grant. Both commands are audited. |
| Roles | `admin` = read + write. `support` = read only (every write returns `403 platform_write_forbidden`). |
| Not a farm role | Farm roles never grant platform access, and a platform role grants nothing on farm routes (an admin with no membership gets `403` from `/farm`). |
| Middleware | `auth:sanctum` → `account.active` → `email.verified` → `platform.admin` (+ `platform.admin:write` and `throttle:platform-admin-write`, 60/min, on writes). |
| Errors | `403 platform_admin_required` (no grant), `403 platform_write_forbidden` (support role writing), `403 account_suspended`, `401`. |

`GET /auth/me` now returns `user.platform_role` (`admin`, `support` or `null`) so the frontend can show the admin area. `GET /platform-admin/me` returns `{user, role, can_write}`.

Conventions: success envelope `{data, meta, message}`; lists are paginated with `page` / `per_page` (max 100) and `meta: {current_page, per_page, last_page, total}`; errors `{message, code, request_id, errors?, details?}`; every response carries `X-Request-Id`.

## Endpoints

| Method + URL | Role | Purpose |
|---|---|---|
| `GET /me` | any | platform identity and role |
| `GET /entitlements` | any | valid feature / limit keys |
| `GET /plans`, `GET /plans/{plan}` | any | all plans (inactive too) with prices, features, limits, `subscribers_count` |
| `POST /plans` | admin | create an **inactive**, non-default plan |
| `PATCH /plans/{plan}` | admin | name, description, `is_public`, `is_active`, `sort_order` |
| `POST /plans/{plan}/make-default` | admin | switch the free fallback plan (idempotent) |
| `PUT /plans/{plan}/prices` | admin | upsert monthly/annual price (integer kobo) |
| `PUT /plans/{plan}/entitlements` | admin | set features and limits |
| `POST /farms/{farm}/subscription/plan` | admin | move one farm to a plan (support/complimentary) |
| `GET /master/{kind}`, `GET /master/{kind}/{id}` | any | reference data incl. inactive |
| `POST /master/{kind}`, `PATCH /master/{kind}/{id}` | admin | create / edit; `kind` = `operation-types`, `species`, `crop-types`, `breeds`, `varieties`, `reference-values` |
| `GET /master/capabilities` | any | capability list with the only allowed `reference_config` keys and rules |
| `GET /master/species/{species}/capabilities` | any | a species' capabilities and configuration |
| `PUT /master/species/{species}/capabilities/{capability}` | admin | enable/disable and configure |
| `GET /work-templates`, `GET /work-templates/{id}` | any | platform templates with `state` |
| `POST /work-templates`, `PATCH /work-templates/{id}` | admin | create a draft / edit |
| `POST /work-templates/{id}/publish`, `.../archive` | admin | publication lifecycle |
| `GET /settings`, `PUT /settings/{key}` | any / admin | closed registry of platform settings |
| `GET /feature-flags`, `POST /feature-flags`, `PATCH /feature-flags/{key}` | any / admin | feature flags |
| `GET /users`, `GET /users/{user}` | any | cross-platform account search and detail |
| `POST /users/{user}/suspend`, `.../restore` | admin | reversible account suspension |
| `GET /farms`, `GET /farms/{farm}` | any | cross-farm search and support overview |
| `GET /audit-logs` | any | platform audit trail |

There is no `DELETE` anywhere: plans, reference data, templates, flags and accounts are deactivated, archived or suspended, never removed.

## Plans, prices, entitlements

* Uses the Phase 3 tables and `EntitlementService`; a change is visible to every farm on the plan immediately.
* `POST /plans` body: `slug` (permanent, `^[a-z][a-z0-9-]*$`, unique), `name`, optional `description`, `currency` (`NGN`), `is_public`, `sort_order`. The plan starts `is_active: false` and grants nothing.
* `PATCH` cannot change `slug` / `currency` / `is_default` (`422`). `409 default_plan_protected` – the default plan cannot be deactivated. `409 plan_in_use` (`details.subscribers`) – a plan with active/past-due subscribers cannot be deactivated; move the farms first.
* `make-default`: target must be active (`409 plan_not_active`) and free (`422 default_plan_must_be_free`). The default plan can never carry an active price.
* `PUT .../prices` body `{"prices":[{"interval":"monthly","amount_minor":750000,"is_active":true}]}`; `amount_minor` ≥ 1 integer kobo; omitted intervals untouched.
* `PUT .../entitlements` body `{"features":{"advanced_reports":true},"limits":{"team_members":{"limit":8},"active_cycles":{"unlimited":true}}}`. Unknown keys, negative numbers, a number together with `unlimited`, or an empty body are `422`. Lowering a limit never deletes data: farms above the new limit keep what they have and are only blocked from growing (`plan_limit_reached`).
* `POST /farms/{farm}/subscription/plan` body `{plan_id, interval?, reason}`: runs `SubscriptionService::changePlan` (the subscription event records the admin as actor, so it also appears in the farm's own audit). `422 plan_not_available`, `409 plan_unchanged`. No payment is taken.

## Reference data and capability schemas

* `code` is permanent identity. An operation type's `category` (and therefore `tracking_model`, which is derived, never client-supplied) and any record's parent (`operation_type_id`, `species_id`, `crop_type_id`) cannot be changed; PATCH accepts `name`, `sort_order`, `is_active` (and a species' `livestock_group`). Sending a fixed field is `422`.
* Create rules: species need a livestock/aquaculture operation type, crop types a crop operation type (`422 operation_type_id`); aquaculture species have no `livestock_group`; duplicate `code`/name is `422`.
* `409 has_active_children` – deactivate an operation type's species and crop types first. `422 parent_inactive` – a record cannot be created or activated under an inactive parent.
* Deactivation hides a value from new selections only; existing production cycles, records and snapshots keep working. `breeds` / `varieties` here are the **system** catalogue only; a farm's custom ones return `404`.
* `PUT .../capabilities/{capability}` body `{"enabled":true,"reference_config":{"incubation_days":21}}`. The config is validated against the capability schema from `GET /master/capabilities` (allowed keys, 1..max days, `*_min <= default <= *_max`, ranges only together); anything else is `422`. Compatibility: `422/409 capability_dependency` (incubation and pregnancy need `supports_breeding`; disable them before breeding), `409 capability_in_use` (`details.active_breeding_projects`) while an active breeding project of that species relies on the capability. Breeding projects already created keep their immutable reference snapshot.

## Work templates

`state`: `draft` → `published` → `archived` (→ `published`). Only published platform templates are visible to farms (`/work-templates`, recommend, apply). A new template needs a permanent unique `code` plus the same content as a farm template (`name`, `applies_to`, `cycle_kind`, species/crop/operation filters, `breeding_workflow`, `items[]`); invalid anchors, recurrence or linked record types are `422`.

* `publish` re-validates everything: at least one item, referenced operation type / species / crop type active, and a breeding template pinned to a species needs that species to support the workflow (`422`). `409 template_already_published`.
* `archive`: `409 template_not_published` unless published. An archived template cannot be edited (`409 template_archived`) or applied (`409 template_inactive`).
* Editing a published template re-validates it and raises `version`. Schedules farms already created keep their own copy.

## Settings and flags

Settings are a closed registry (`GET /settings`): `support_email`, `support_whatsapp`, `announcement`. Each has a value rule; unknown keys are `404 unknown_setting`, bad values `422`; `null` clears. Feature flags: `POST {key, description?}` (created off, key permanent), `PATCH {enabled?, description?}`; never deleted. Application code reads them with `PlatformConfigService::setting()` / `flag()`.

## Support: users, farms, audit

* `GET /users` filters: `q` (name/email), `status` (`active|suspended`), `platform_role`, `verified`. Detail adds memberships and linked provider names. Never returns password hashes, tokens, one-time codes or provider identifiers.
* `POST /users/{user}/suspend` body `{reason}`: blocks sign-in, revokes API tokens now (cookie sessions end on the next request), keeps memberships and farm data. `409 cannot_suspend_self | platform_admin_protected | already_suspended`. `restore`: `409 not_suspended`.
* `GET /farms` filters: `q` (farm name or any member's name/email), `plan` (slug), `subscription_status`, `sort` (`name|created_at`), `direction`. Rows: owner, plan, subscription status, `members_count`. Detail adds team, selected operation codes, effective entitlements with current `usage` per limit and the last 10 subscription events. **No** population, stock, finance, sales, health or breeding data is exposed.

## Audit

Platform actions are written to the Phase 17 `audit_logs` table with `farm_id = NULL`, so a farm's `/audit` never returns them. `GET /audit-logs` filters: `from`/`to` (UTC days), `actor_id`, `action`, `resource_type`, `resource_id`, `request_id`. Each entry: `action` (`platform.*`), `resource {type,id,label}`, `actor`, `performed_at`, `request_id`, `ip_address`, and `changes` holding only the safe `before`/`after` of fields that actually changed (plus a `reason` where required). Credential-like keys are dropped; secrets are never recorded. The entry is written in the same transaction as the change, so a failed change leaves no entry.

Actions: `platform.plan_created|plan_updated|plan_default_changed|plan_prices_updated|plan_entitlements_updated|farm_plan_changed`, `platform.master_created|master_updated|species_capability_updated`, `platform.template_created|template_updated|template_published|template_archived`, `platform.setting_updated`, `platform.flag_created|flag_updated`, `platform.user_suspended|user_restored`, `platform.admin_granted|admin_revoked` (console).
