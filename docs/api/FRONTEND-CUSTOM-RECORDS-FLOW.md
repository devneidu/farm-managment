# Frontend flow — Farm-made record types ("My own records")

A farm can invent its own record types ("Egg check", "Visitor log") with up to 15 fields, then record against a batch or crop project using a form the app builds **from the type's field list**. Built-in record types (mortality, feed use, eggs, …) are unchanged. All examples are real responses from the running code (ids shortened with `…`). Send `idempotency_key` on every record.

Permissions: **Owner and Manager** make and edit types (`master_data.manage`, the same permission as custom breeds and varieties). Anyone with `master_data.view` can read the list; anyone with `record.create` can record with a type. The platform admin sees section 7.

A custom record has **no measurement, population, stock or money effect**. It is a note with fields, kept with its date (`recorded_at`) and reversible like any record.

## 1. Record types settings page

`GET /custom-record-types` (`master_data.view`) lists the farm's own types (`source: "farm"`) and the public ones (`source: "public"`), A–Z. Add `?include_archived=true` to show archived ones, `?source=farm|public`, `?applies_to=livestock|crop|both`.

```json
{ "data": [
  { "id": "01a1bcc2-28c0-…", "name": "Biosecurity visit", "description": "Who came onto the farm", "source": "public", "status": "active",
    "applies_to": "both", "species": null, "crop_type": null, "version": 1, "copied_from_id": null,
    "in_use": false, "records_count": 0, "can_edit": false,
    "fields": [ { "key": "visitor", "label": "Visitor", "kind": "short_text", "required": true, "hidden": false, "locked": false },
                { "key": "boots_washed", "label": "Boots washed", "kind": "yes_no", "required": false, "hidden": false, "locked": false } ] },
  { "id": "01a1bcc2-22b7-…", "name": "Egg check", "source": "farm", "status": "active", "applies_to": "livestock", "version": 1,
    "in_use": true, "records_count": 2, "can_edit": true, "fields": [ … ] } ],
  "meta": [], "message": null }
```

- **Public** types: show "Use" and **Copy to my farm**; no edit (`can_edit: false`).
- **Farm** types: Edit, Archive / Unarchive. `records_count` is this farm's records of the type; `in_use` true means the **edit rules** (section 4) apply and every field has `locked: true`.
- Copy: `POST /custom-record-types/{public id}/copy` with optional `{ "name": "Visitor log" }` → 201, a private editable copy, version 1, `copied_from_id` set. While the farm has an active copy, the public original is no longer offered in its record forms (it still lists on this page). Copy needs a **public, active** type (`404` otherwise); a name already used by the farm is `409 duplicate_name`.
- Archive: `POST /custom-record-types/{id}/archive` → type leaves every record form; old records stay readable. `409 type_already_archived`; undo with `/unarchive` (`409 type_not_archived`).
- Workers and vets see the list read-only (no New / Edit / Archive buttons: they lack `master_data.manage`; `POST` is `403`).

## 2. New-type builder — `POST /custom-record-types`

```json
{ "name": "Egg check", "description": "Quick daily check of the egg room", "applies_to": "livestock",
  "fields": [
    { "label": "Crates collected", "kind": "number", "unit": "crates", "min": "0", "max": "500", "required": true },
    { "label": "Cracked eggs", "kind": "whole_number" },
    { "label": "Shell colour", "kind": "choose_one", "options": ["White", "Brown", "Mixed"] },
    { "label": "Checked on", "kind": "date" },
    { "label": "House clean", "kind": "yes_no" },
    { "label": "Remark", "kind": "short_text" } ] }
```

→ `201`. The server makes each field's `key` from its label (`crates_collected`, `cracked_eggs`, …; a repeated label-key gets `_2`; the key never changes afterwards). Response (trimmed):

```json
{ "data": { "id": "01a1bcc2-22b7-…", "name": "Egg check", "source": "farm", "status": "active", "applies_to": "livestock", "species": null, "crop_type": null,
    "version": 1, "in_use": false, "records_count": 0, "can_edit": true,
    "fields": [ { "key": "crates_collected", "label": "Crates collected", "kind": "number", "unit": "crates", "min": "0", "max": "500", "required": true, "hidden": false, "locked": false }, … ] },
  "meta": [], "message": "Record type created." }
```

- `applies_to`: `livestock` (batches), `crop` (crop projects) or `both`. Optional `species_id` (livestock only) or `crop_type_id` (crop only) narrows it to one species / crop. Take the ids from `GET /master/...` lists.
- At most **15 fields**. Field labels must differ from each other. Name: 2–80 characters, unique on the farm (archived too, any capitals/spacing), and never a built-in record type's name or code ("Mortality", "Feed usage", `feed_use`, "Eggs"…).
- Errors: `422` field/name problems; `409 duplicate_name` (`details.existing_id`).

```json
HTTP 422  { "message": "This name is already used by a built-in record type. Choose another.", "code": "validation_failed",
            "errors": { "name": ["This name is already used by a built-in record type. Choose another."] } }
HTTP 422  { "message": "The fields field must not have more than 15 items.", "errors": { "fields": ["The fields field must not have more than 15 items."] } }
HTTP 409  { "message": "A record type with this name already exists.", "code": "duplicate_name", "details": { "existing_id": "01a1bcc2-22b7-…", "source": "farm", "status": "active" } }
```

## 3. Field kinds

| `kind` | Extra properties | The record form shows | Stored value |
|---|---|---|---|
| `number` | `unit` (text, e.g. "kg"), `min`, `max` (decimal strings) | decimal input + unit | exact decimal **string** (`"12.5"`; up to 12 digits, 6 decimals) |
| `whole_number` | – | integer input, 0 or more | integer (`3`) |
| `short_text` | – | one line, max 200 | string |
| `long_text` | – | text area, max 2000 | string |
| `date` | – | date picker | `"YYYY-MM-DD"` |
| `yes_no` | – | switch / checkbox | boolean (`true`/`false`, not "yes" or 1) |
| `choose_one` | `options` (1–20 texts, max 80 each, no repeats) | select | the chosen option text |

Every field also has `required` (default false) and `hidden` (default false). Number sums in the report use `number` and `whole_number`.

## 4. Editing rules — `PATCH /custom-record-types/{id}`

Send the **complete `fields` list**; existing fields carry their `key`, a new field has none. You can also change `name` / `description` alone.

Before any record exists, everything can change. **Once records exist** (`in_use: true`):

| You may | You may not (`409 custom_type_in_use`) |
|---|---|
| add fields (required or not) | delete a field (hide it instead) |
| rename labels, name, description | change a field's kind |
| add `choose_one` options | remove an option |
| make a required field optional | make an optional field required (add a new required field) |
| reorder, hide fields | change a number's unit |
| widen `min` / `max` | narrow `min` / `max` · change `applies_to` / species / crop |

The version goes up whenever the fields change; each record keeps the `custom_schema_version` it was captured with, and labels shown on old records are the current ones. Real refusals:

```json
HTTP 409  { "message": "The field \"Crates collected\" already has records, so it cannot be deleted. Hide it instead.", "code": "custom_type_in_use", "details": { "field_key": "crates_collected", "rule": "delete" } }
HTTP 409  { "message": "The choice \"Brown\" of \"Shell colour\" is already used by records, so it cannot be removed. Hide the field or add a new one.", "details": { "field_key": "shell_colour", "rule": "option" } }
HTTP 409  { "message": "The field \"Cracked eggs\" already has records, so its type cannot change. Add a new field instead.", "details": { "field_key": "cracked_eggs", "rule": "kind" } }
```

`details.rule`: `delete`, `kind`, `option`, `required`, `unit`, `limits`, `applies_to`. Use `fields[].locked` / `in_use` to grey out the controls up front. A **hidden** field disappears from new forms and is not accepted in new records (a hidden required field is no longer asked for); records already saved keep and still show its value. A successful edit that adds a "Collected by" field, relabels "Crates collected" → "Crates" and hides "Remark" returns `200` with `version: 2`.

## 5. Recording with a custom type

Build the form from either source (both are filtered to types that fit the batch and are active):

- `GET /production-cycles/{id}` → `available_record_types` now also lists `{ "type": "custom", "custom_record_type_id": "01a1bcc2-…", "name": "Egg check", "permissions_required": {…} }` (after the built-ins).
- `GET /master/record-types` → built-ins first, then one entry per recordable custom type with `type: "custom"`, `custom_record_type_id`, `name`, `version`, `applies_to`, `custom_fields` (the **visible** fields to render, in order), `measurement: null`, `population_effect: "none"`, `permission: "record.create"`, `inventory_effect_enabled: false`.
- `GET /master/record-operations` (display mode `normal`) lists `{ "code": "custom:01a1bcc2-22b7-…", "label": "Egg check", "display_mode": "normal", "cycle_kind": "livestock" }`; `GET /master/record-operations/custom:{id}` returns the form: `submission.body_defaults = { "type": "custom", "custom_record_type_id": "…" }` and `fields.required` / `fields.optional` (`details.<key>`).
- The dashboard `quick_record` also offers farm-made types, **first** (at most 2 of the 6 tiles): `{ "type": "custom", "custom_record_type_id": "…", "label": "Egg check", "kind": null, "applies_to_cycles": 2 }`. Open the form from the same `custom:{id}` operation.

Submit — `POST /records` (`record.create`; same rules as every record: `recorded_at` with an offset, not before the cycle start or in the future, active cycle):

```json
{ "production_cycle_id": "01a1bcc2-1ad8-…", "type": "custom", "custom_record_type_id": "01a1bcc2-22b7-…",
  "recorded_at": "2026-02-01T10:00:00+01:00", "idempotency_key": "egg-check-1", "notes": "Morning round",
  "details": { "crates_collected": "12.5", "cracked_eggs": 3, "shell_colour": "Brown", "checked_on": "2026-02-01", "house_clean": true, "remark": "Feed low" } }
```

→ `201`:

```json
{ "data": { "id": "01a1bcc2-2a81-…", "type": "custom", "type_name": "Egg check", "custom_record_type_id": "01a1bcc2-22b7-…", "custom_schema_version": 1,
    "custom": { "type": { "id": "01a1bcc2-22b7-…", "name": "Egg check", "source": "farm", "status": "active", "version": 1 },
                "fields": [ { "key": "crates_collected", "label": "Crates collected", "kind": "number", "unit": "crates", "hidden": false, "value": "12.5" },
                            { "key": "cracked_eggs", "label": "Cracked eggs", "kind": "whole_number", "unit": null, "hidden": false, "value": 3 }, … ] },
    "details": { "crates_collected": "12.5", "cracked_eggs": 3, "shell_colour": "Brown", "checked_on": "2026-02-01", "house_clean": true, "remark": "Feed low" },
    "measurement": null, "population_delta": 0, "recorded_at": "2026-02-01T09:00:00.000000Z", "notes": "Morning round", "reversed_by_record_id": null },
  "message": "Operational record saved." }
```

`recorded_at` (when it happened) stays apart from `created_at`. `details` accepts **exactly the type's visible field keys**; required fields must be present, optional ones may be left out or `null`. Show `custom.fields[]` (label + value) when displaying a record; `type_name` is also on every built-in record now.

Reading and reversing: `GET /records?type=custom&custom_record_type_id={id}` (and `GET /records/{id}`) work as for any record; **reverse** with `POST /records/{id}/reverse` (`record.reverse`); a replacement uses `corrects_record_id` with the **same** type (`409 invalid_correction` otherwise). Archived types: old records stay readable, new ones are refused.

Errors:

```json
HTTP 422  { "message": "The details field must be an array. (and 2 more errors)", "code": "validation_failed",
            "errors": { "details": ["The details field must be an array."], "details.crates_collected": ["It must be at most 500."],
                        "details.shell_colour": ["The selected details.shell colour is invalid."] } }
HTTP 422  { "message": "This record type is for livestock batches, not crop projects.", "errors": { "custom_record_type_id": ["This record type is for livestock batches, not crop projects."] } }
```

The first shows an unknown key (`surprise`), a number above its `max` and a choice outside the options; the `details` message is the same one built-in types give for an unknown key. `custom_record_type_id` is also `422` when the type is archived, does not fit the species/crop, is unknown **or belongs to another farm** (same answer, no leak). `type: "custom"` without `custom_record_type_id`, or an id with a built-in type, is `422`. Same key + same body = the original record; same key + different body = `409 idempotency_conflict`. Closed cycle: `409 cycle_closed`.

## 6. The report — "Farm-made records"

`GET /reports/custom_records?from=2026-01-01&to=2026-12-31` (needs `report.view`, `record.view`, `production_cycle.view`; optional `production_cycle_id`, `custom_record_type_id`). It is in `GET /reports` (`family: "records"`, `exportable` per plan). One row per type, batch and **number field** (`number` and `whole_number`); reversed records and their reversals are left out.

```json
{ "data": { "report": { "code": "custom_records", "title": "Farm-made records" }, "filters": { "from": "2026-01-01", "to": "2026-12-31" },
    "columns": [ { "key": "type", "label": "Record type", "type": "string" }, { "key": "reference", "label": "Reference", "type": "string" }, { "key": "name", "label": "Batch / project", "type": "string" },
                 { "key": "records", "label": "Records", "type": "integer" }, { "key": "field", "label": "Number field", "type": "string" }, { "key": "unit", "label": "Unit", "type": "string" }, { "key": "sum", "label": "Total", "type": "decimal" } ],
    "rows": [ { "type": "Egg check", "reference": "BAT-2026-00001", "name": "Layer flock", "records": 2, "field": "Crates", "unit": "crates", "sum": "29" },
              { "type": "Egg check", "reference": "BAT-2026-00001", "name": "Layer flock", "records": 2, "field": "Cracked eggs", "unit": null, "sum": "3" } ],
    "summary": { "records": 2, "sums": [ { "type": "Egg check", "field": "Crates", "unit": "crates", "sum": "29" }, { "type": "Egg check", "field": "Cracked eggs", "unit": null, "sum": "3" } ] } },
  "meta": { "current_page": 1, "per_page": 100, "last_page": 1, "total": 2 } }
```

`records` is the number of records of that type in that batch (it repeats on each field row; `summary.records` counts every record once). A type with no number field shows one row with `field: null` and `sum: null`. Sums are exact decimal strings. **CSV / Excel / PDF**: `POST /reports/exports` with `{ "report": "custom_records", "format": "csv", "filters": {…}, "idempotency_key": "…" }` (needs `report.export` and the plan's data export) — the file has the same columns:

```
"Record type",Reference,"Batch / project",Records,"Number field",Unit,Total
"Egg check",BAT-2026-00001,"Layer flock",2,Crates,crates,29
"Egg check",BAT-2026-00001,"Layer flock",2,"Cracked eggs",,3
```

A `custom_record_type_id` of another farm is `422` on that key.

## 7. Platform admin: farm-made types

Separate from the farm app (needs a platform grant; farm Owner/Manager get `403 platform_admin_required`). Reads: any platform role; writes: role `admin`.

- `GET /platform-admin/custom-record-types` — what farms made (`source=farm`, default) or the public types (`?source=public`); filters `status`, `applies_to`, `q` (name), `page`, `per_page`. Each row: name, `fields`, the making `farm` (`id`, `name`), `records_count`, `farms_using` (counts only — **never the records**), `copies_count` (farm copies of it), `public_copy_id`.
- `POST /platform-admin/custom-record-types/{id}/make-public` → `201`. Creates a **public copy** (version 1, hidden fields dropped); the farm's original stays private and unchanged. Every farm then sees it (section 1). Errors: `409 type_archived`, `409 already_public` (`details.existing_id`), `409 duplicate_name` (another public type has the name), `422 fields` (all fields hidden), `404` for a public id.
- `POST /platform-admin/custom-record-types/{id}/archive` and `/unarchive` — **public** types only. Archived: farms can no longer record with it, old records stay readable, farm copies keep working. `409 type_already_archived` / `type_not_archived`; farm-made types are `404`.

```json
POST /platform-admin/custom-record-types/01a1bcc2-22b7-…/make-public
HTTP 201  { "data": { "id": "01a1bcc2-4d41-…", "name": "Egg check", "source": "public", "status": "active", "applies_to": "livestock", "version": 1, "farm": null,
    "copied_from_id": "01a1bcc2-22b7-…", "records_count": 0, "farms_using": 0, "copies_count": 0, "public_copy_id": null,
    "fields": [ { "key": "crates_collected", "label": "Crates", "kind": "number", "unit": "crates", "min": "0", "max": "500", "required": true, "hidden": false }, … ] }, "meta": [], "message": null }
(second call)  HTTP 409  { "message": "A public copy of this record type already exists.", "code": "already_public", "details": { "existing_id": "01a1bcc2-4d41-…" } }
```

Admin page layout: a table of farm-made types (name, farm, fields count, records, farms), a detail drawer with the field list, and a **Make public** button (disabled when `public_copy_id` is set); a second tab for public types with **Archive**.

## 8. Errors at a glance

| When | Response |
|---|---|
| Not signed in | 401 `unauthenticated` |
| Create / edit / archive / copy without `master_data.manage` | 403 `forbidden` |
| Record without `record.create` | 403 `forbidden` |
| Another farm's type id (show, edit, archive, copy) | 404 `not_found` |
| Editing or archiving a public type from the farm app | 404 `not_found` |
| Bad field list, name of a built-in type | 422 `validation_failed` (keyed `fields.N.…` / `name`) |
| Name already taken | 409 `duplicate_name` |
| Edit that changes the meaning of existing records | 409 `custom_type_in_use` (`details.field_key`, `details.rule`) |
| Record: wrong details | 422 on `details.<key>` |
| Record: archived / unknown / foreign / non-matching type | 422 on `custom_record_type_id` |
| Record: closed cycle | 409 `cycle_closed` |
| Archive twice / unarchive an active type | 409 `type_already_archived` / `type_not_archived` |
