# Reports, exports, notifications and audit — Phase 17

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. The farm is resolved server-side; there is no `farm_id` input. Machine-readable schemas are in `openapi.json`.

Everything in this phase is **derived**. Reports are computed from the authoritative ledgers and records every time they run; nothing a report shows is stored. An export is a lifecycle row plus a private file rendered from the same report code path. A notification is a per-user inbox record that points at its source and never changes it. The audit trail is a read model over the existing append-only records plus a small log of privileged actions.

| Endpoint | Permission | Purpose |
|---|---|---|
| `GET /reports` | `report.view` | report catalogue for this farm and viewer |
| `GET /reports/{report}` | `report.view` + the report's data permissions | run one report (JSON) |
| `POST /reports/exports` | `report.export` + report permissions + plan feature `data_export` | queue a CSV/XLSX/PDF export |
| `GET /reports/exports` | `report.export` | my exports |
| `GET /reports/exports/{export}` | `report.export` | status of one of my exports |
| `GET /reports/exports/{export}/download` | `report.export` + report permissions + `data_export` | download the file |
| `GET /notifications` | any active member | my notifications (this farm) |
| `POST /notifications/{notification}/read` | any active member | mark one of mine read |
| `POST /notifications/read-all` | any active member | mark all of mine read |
| `GET /notification-preferences` | any active member | my channel and per-type switches |
| `PATCH /notification-preferences` | any active member | change them |
| `GET /audit` | `audit.view` | who did what, when, to which resource |

New permissions: `report.view` (Owner, Manager, Finance, Farm Worker, Vet), `report.export` (Owner, Manager, Finance, Vet), `audit.view` (Owner, Manager). The legacy `GET|PUT /settings/notifications` (channel switches only) keeps working and no longer discards per-type choices.

## Reports

### Access rules

A report needs `report.view` **and** the permissions of the data it reads. A report whose data the viewer cannot see is **not listed** (its existence is not advertised) and running it is `403 forbidden`. Roles are never compared: a Farm Worker or Vet does not receive finance, sales, invoice or contact reports, a Finance user does not receive health or breeding reports, because their permission sets differ.

Reports marked **advanced** also need the `advanced_reports` plan entitlement (`403 feature_not_available`; the catalogue lists them with `available: false`, `unavailable_reason: "feature_not_available"`, `required_feature: "advanced_reports"` so the UI can explain it). Exports need `data_export`.

### Catalogue — `GET /reports`

```json
{ "data": [
  { "code": "livestock_population", "title": "Livestock population", "family": "livestock",
    "description": "Opening head, additions, deaths, sales and adjustments per livestock cycle, reconciled to the population ledger.",
    "filters": ["from", "to", "status", "production_cycle_id"], "applies_to": "livestock", "relevant": true,
    "available": true, "unavailable_reason": null, "required_feature": null, "exportable": true, "export_formats": ["csv", "xlsx", "pdf"] }
], "meta": {}, "message": null }
```

`relevant` is false when the farm has no operation or cycle of that kind (`applies_to`: `livestock` | `crop`), so a crop-only farm can hide livestock reports.

| Code | Family | Data permissions (all) | Plan | Rows | Notes |
|---|---|---|---|---|---|
| `cycle_performance` | production | `production_cycle.view`, `record.view` | advanced | one per cycle active in the period | dates, days in production, head at period start/end (ledger), deaths, mortality %, crop planting baseline |
| `livestock_population` | livestock | `record.view`, `production_cycle.view` | – | one per livestock cycle | opening + initial/births/deaths/sold/adjustments/other = closing; signed movements, reversals netted against the event they reverse |
| `mortality` | livestock | `record.view`, `production_cycle.view` | – | cycle × cause | reversed mortality records excluded; causes compared case-/space-insensitively |
| `livestock_growth` | livestock | `record.view`, `production_cycle.view` | – | cycle × weight unit | weigh-ins, sampled head, weighted average weight per head, latest sample average |
| `production_output` | production | `record.view`, `production_cycle.view` | – | cycle × product × unit | egg collections and milk; summed only within one unit |
| `crop_performance` | crops | `record.view`, `production_cycle.view` | – | crop project (× harvest unit) | planting units planted/lost in period, latest establishment check, harvest by unit |
| `breeding_outcomes` | breeding | `breeding.view` | – | one per project | expected window vs net live/lost counts |
| `health_treatment` | health | `health.view` | – | cycle × event type | events, animals affected, medicine lines; reversed events excluded |
| `inventory_stock` | inventory | `inventory.view` | – | one per item | balance as of a farm-local day (`as_of`) from the movement ledger, low-stock flag |
| `input_consumption` | inventory | `inventory.view` | – | item × cycle × origin | stock used (reason `use`) net of reversals: feed and crop inputs, medicine, other |
| `income_expense` | finance | `finance.view` | advanced | category × direction | reuses the finance summary; reversals offset their entries |
| `cycle_profitability` | finance | `finance.view`, `production_cycle.view` | advanced | one per cycle (+ "not allocated") | income, expense, net |
| `sales_summary` | sales | `sale.view` | advanced | line kind | cancelled sales excluded and counted separately |
| `receivables` | sales | `invoice.view`, `payment.view` | advanced | one per customer | invoiced, received (net of reversed payments), outstanding, overdue; void invoices excluded |
| `task_compliance` | work | `task.view` | – | task category | only tasks the viewer may see; on time / late / overdue / upcoming; completion rate |
| `contact_history` | contacts | `contact.view` + (`purchase.view` or `sale.view`) | advanced | one per contact | money columns appear only for the permissions the viewer holds |

### Run — `GET /reports/{report}`

Query: `from`, `to` (inclusive **farm-local** days, `Y-m-d`; default the last 30 days ending today), `as_of` (point-in-time reports), `production_cycle_id`, `kind`, `status`, `per_page` (1–500, default 100), `page`. Filters a report does not list in `filters` are ignored. Validation: `to` before `from` → `422`; a `production_cycle_id` that does not exist **or belongs to another farm** → the same `422`.

```json
{
  "data": {
    "report": { "code": "mortality", "title": "Mortality by cause", "family": "livestock", "description": "…" },
    "farm": { "id": "…", "name": "Green Acres", "currency": "NGN" },
    "filters": { "from": "2026-09-15", "to": "2026-10-14" },
    "timezone": "Africa/Lagos", "generated_at": "2026-10-14T11:00:00.000000Z",
    "columns": [ { "key": "cause", "label": "Cause", "type": "string" }, { "key": "deaths", "label": "Deaths", "type": "integer" } ],
    "rows": [ { "reference": "BAT-2026-00001", "name": "Layers A", "cause": "Disease", "records": 2, "deaths": 6 } ],
    "summary": { "deaths": 6, "records": 2 },
    "notes": []
  },
  "meta": { "current_page": 1, "per_page": 100, "last_page": 1, "total": 1 }, "message": null
}
```

* Column `type`: `string`, `integer`, `decimal` (quantities), `money` (two decimals), `percent`, `date`, `datetime`. Decimals, money and percentages are **strings**; never convert them to floats.
* `summary` is computed over **all** rows, not only the page. Money totals are exact two-decimal strings in the farm currency.
* Quantities are in the **canonical normalised unit** (grams for weight, millilitres for volume, pieces for counts) and are never added across different units or dimensions; where a report could mix units it returns one row or one total per unit. Stock quantities are shown in each item's own stock unit.
* Reversed and cancelled data: reversed operational/health records and outcomes, cancelled purchases/sales, void invoices and reversed payments are excluded or netted exactly as stated in each report's `notes`.
* Day boundaries are farm-local days converted to UTC instants; the 23:30 UTC / 00:30 Lagos case belongs to the next farm day.
* Errors: `403 forbidden`, `403 feature_not_available`, `404 report_not_found`, `422`.

## Exports

### Request — `POST /reports/exports`

```json
{ "report": "income_expense", "format": "xlsx", "filters": { "from": "2026-10-01", "to": "2026-10-14" }, "idempotency_key": "6f9b…" }
```

`202` with an export in status `queued` (the work happens on the database queue). Omitted dates are defaulted **when the request is made** and the resolved filters are stored on the export and echoed back, so the file always matches what was asked for. Requests are rate limited per user (20 per hour by default).

```json
{ "data": { "id": "…", "report": "income_expense", "format": "xlsx", "filters": { "from": "2026-10-01", "to": "2026-10-14" }, "status": "queued",
  "row_count": null, "file_name": null, "file_size": null, "download_path": null, "error_code": null, "error_message": null,
  "requested_at": "…", "started_at": null, "completed_at": null, "failed_at": null, "expires_at": null, "download_count": 0 }, "meta": {}, "message": "Export queued." }
```

Idempotency: `idempotency_key` is required and unique per user and farm. The same key with the same content returns the **same** export (`200`, `Export already requested.`) and queues nothing; the same key with a different report, format or filters is `409 idempotency_conflict`.

### Lifecycle

`queued` → `processing` → `completed` | `failed`; a completed export becomes `expired` after the retention period (7 days, `REPORT_EXPORT_RETENTION_DAYS`) when `reports:prune-exports` (daily) removes the file. Poll `GET /reports/exports/{id}` or use the `export_ready` / `export_failed` notification. A failed export carries `error_code` (`export_failed`, `forbidden`, `access_revoked`, `feature_not_available`) and a safe `error_message`; internal errors are logged server-side and never exposed. Request a new export to retry.

### Formats

* `csv` — UTF-8 with BOM, header row then data rows, no totals. Text that a spreadsheet could execute (`=`, `+`, `-`, `@` prefixes) is prefixed with an apostrophe.
* `xlsx` — one worksheet: title, farm, generation time and timezone, the filters, the table, a summary block and the notes. Numbers are written with their exact decimal text; text is stored as inline strings.
* `pdf` — landscape A4 table with the same header, filters, summary and notes.

### Download — `GET /reports/exports/{export}/download`

Returns the file with `Content-Disposition: attachment`, `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`.

* Only the user who requested the export, on the farm it was requested for, can see or download it; anyone else (same farm, other member or another farm) receives a plain `404`.
* Files are written to a private disk (`storage/app/private/report-exports/{farm_id}/{export_id}.{ext}`), never to a public path; the storage path is never returned.
* Access is **re-checked** when the file is generated (the requester must still be an active member holding the permissions and plan feature) and again on every download, so a demoted user cannot fetch an older file.
* `409 export_not_ready` (queued/processing), `409 export_failed`, `410 export_expired`, `403` when the current permissions or plan no longer allow it.

## Notifications

A notification is created by background evaluation (`notifications:generate`, hourly, one job per farm on the database queue) in three steps that stay separate: **condition** (the Phase 16 insights and task reminders) → **decision** (is this member allowed to see it? is the type switched on? was it already announced?) → **record** (a row in that member's inbox, plus an email copy when the email channel is on). The originating farm data is never modified; read state belongs to the notification.

### Types

| Type | Severity | Needs permission | Source |
|---|---|---|---|
| `mortality_threshold` | warning / critical | `record.view` | production cycle |
| `low_stock` | warning / critical (empty) | `inventory.view` | inventory item |
| `lot_expiry` | warning / critical (expired) | `inventory.view` | inventory lot |
| `overdue_tasks` | warning / critical (5+) | `task.view` | farm-wide |
| `task_reminder` | info | `task.view` | the task, for its assignee (directly or by role) at the task's `reminder_offsets` |
| `overdue_invoices` | warning | `invoice.view` | farm-wide |
| `medicine_withdrawal` | info | `health.view` | farm-wide |
| `breeding_due_soon`, `breeding_overdue` | info / warning | `breeding.view` | breeding project |
| `cycle_past_expected_end` | info | `production_cycle.view` | production cycle |
| `export_ready`, `export_failed` | info / warning | `report.export` | the export (requester only) |

**Deduplication**: a condition is announced at most once per farm-local **day** (critical/warning) or **week** (info) per user while it persists; a task reminder once per task and reminder time; an export once. This is enforced by a unique key `(user, farm, dedupe_key)`, so concurrent workers cannot duplicate either. The farm-local day, not the UTC day, defines the period.

### `GET /notifications`

Query `unread` (`true` unread only, `false` read only), `type`, `severity`, `page`, `per_page`. Only **your** in-app notifications on the current farm are returned. `meta.unread_count` is the total unread count.

```json
{ "data": [ { "id": "…", "type": "low_stock", "severity": "warning", "title": "Low stock",
  "message": "Layer mash is at or below its low-stock threshold.",
  "source": { "type": "inventory_item", "id": "…", "reference": "Layer mash" },
  "data": { "why": { "rule": "low_stock", "stock": { "quantity": "40", "unit": "kg" }, "threshold": { "quantity": "50", "unit": "kg" } }, "insight_id": "low_stock:…", "period": "2026-10-14" },
  "is_read": false, "read_at": null, "created_at": "…" } ],
  "meta": { "current_page": 1, "per_page": 25, "last_page": 1, "total": 1, "unread_count": 1 }, "message": null }
```

`source` is `null` for farm-wide conditions. `POST /notifications/{id}/read` is idempotent (the first `read_at` is kept); another user's or farm's id is `404`. `POST /notifications/read-all` returns `{ "updated": n }` and only touches your own.

### Preferences

`GET /notification-preferences` → `{ "channels": { "in_app": true, "email": true }, "types": [ { "code": "low_stock", "label": "…", "description": "…", "enabled": true } ] }`; only the types **you** can receive are listed. `PATCH` body `{ "channels": { "email": false }, "types": { "low_stock": false } }` (both optional, omitted values keep their setting); an unknown channel (there is no WhatsApp channel), an unknown type or a type your role cannot receive is `422`. A type or channel that is off means the notification is not created for you. With in-app off and email on, the email is still sent but the notification does not appear in the centre.

## Audit trail — `GET /audit`

Requires `audit.view` (Owner, Manager). A newest-first timeline for the current farm. It is built **in place** from the authoritative append-only records, so there is no second operational history, plus an `audit_logs` table for privileged actions nothing else records. A source is only included when the viewer may also view that module.

Query: `from`, `to` (farm-local days on `performed_at`), `actor_id`, `action`, `resource_type`, `resource_id` (everything that happened to one resource, including the reversal/correction rows pointing at it), `request_id`, `production_cycle_id`, `page`, `per_page` (≤100).

```json
{ "data": [ { "id": "record:…", "action": "record.reversed", "resource": { "type": "operational_record", "id": "…", "label": "mortality" },
  "actor": { "id": "…", "name": "Wale Worker" }, "performed_at": "2026-10-14T11:00:00.000000Z", "recorded_at": "2026-10-14T06:00:00.000000Z",
  "changes": null, "request_id": "audit-request-0001", "ip_address": null, "related_id": "<the reversing row>", "production_cycle": { "id": "…", "reference": "BAT-2026-00001", "name": "Layers A" } } ],
  "meta": { "current_page": 1, "per_page": 50, "last_page": 1, "total": 1 }, "message": null }
```

* `performed_at` = when the action happened in the system; `recorded_at` = the business time the event was recorded as. They are never merged.
* `request_id` is the id returned in the `X-Request-Id` header of the request that caused the entry. `ip_address` and `changes` exist only on audit-log entries.
* Reversals and corrections are first-class entries (`*.reversed`, `*.corrected`): `resource` is the original and `related_id` is the reversing or correcting row.
* **Never included**: money amounts, record details/notes/reasons, invitation tokens or any secret. `changes` holds only safe facts (role from/to, changed setting names, export format and filters).

Actions: `record.created|reversed|corrected`, `health_record.created|reversed|corrected`, `inventory.stock_in|stock_out|adjusted|transferred|reversed` (standalone movements only; movements that are part of a record, purchase, sale or health event belong to that event), `finance.recorded|reversed|corrected` (manual entries), `purchase.created|cancelled`, `sale.created|cancelled`, `invoice.issued|voided`, `payment.recorded|reversed`, `breeding.project_started`, `breeding.outcome_recorded|reversed`, `task.completed`, `production_cycle.<created|updated|closed|reopened>`, `subscription.<event type>`, and from the audit log `team.invitation_sent|resent|revoked|accepted`, `team.member_role_changed`, `team.member_removed`, `farm.settings_updated`, `report.export_requested`, `report.export_downloaded`.

## Performance

Reports use one grouped SQL query per source (the query count does not grow with the number of cycles); the audit page runs a constant number of queries; indexes were added for records by type/time and the population ledger by cycle/time. Exports and notification evaluation run on the database queue. No caching is used; Phase 21 owns broader hardening.
