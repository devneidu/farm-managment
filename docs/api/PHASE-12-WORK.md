# Work — tasks, schedules, templates and calendar (Phase 12)

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. Farm ownership is resolved server-side; **never send `farm_id`** (rejected 422). Unknown or foreign task, schedule, template, cycle and project IDs return `404 not_found`. Responses use the envelope `{data, meta, message}`; errors use `{message, code, request_id, errors?}`.

## Concepts

| Concept | Meaning | Endpoint family |
|---|---|---|
| **Task** | Work that **should** happen. | `/tasks` |
| **Schedule** | A recurrence rule that generates tasks. | `/schedules` |
| **Work template** | A reusable plan (platform or farm) applied to a cycle or breeding project. | `/work-templates` |
| **Calendar** | A read model of tasks plus milestones. Stores and duplicates nothing. | `/calendar` |
| **Operational record** | What **did** happen (Phases 8, 10, 11). | `/records`, `/health-records`, `/breeding-projects/...` |

There is no separate "Schedule" screen model: work is Tasks + Calendar; schedules are only the rules behind recurring tasks.

**A task never creates an actual record.** Not on creation, not on generation, not on completion. Completing either just closes the task, or links an actual record that was **already saved** through its own endpoint.

## Task lifecycle and due state

Stored `status`: `open` → `completed` | `cancelled` (final). There is no "missed" state: an unfinished task stays open and overdue until completed or cancelled.

`due_state` is **derived**, never stored: `upcoming`, `due_today`, `overdue`, `completed`, `cancelled`. Completed/cancelled win over overdue.

- `due_date` (`YYYY-MM-DD`) and optional `due_time` (`HH:MM`) are **farm-local** (default `Africa/Lagos`). A task without a time is due by the **end of its day**.
- `due_at` (UTC ISO-8601) is the instant after which an open task is overdue; `timezone` is returned for presentation.
- "Today" is the farm's local calendar date, so at 23:00 UTC (00:00 Lagos) yesterday's tasks become overdue.
- `GET /tasks?due_state=` uses the same rule.

## Recurrence

`recurrence`: `none` (one task on `starts_on`), `daily` (every `interval_value` days), `weekly` (every `interval_value` weeks on `weekdays`, ISO 1=Mon … 7=Sun; default the weekday of `starts_on`). Optional `ends_on` and/or `occurrence_limit` (counted from `starts_on`). Monthly/yearly rules are not supported yet.

Tasks are generated for today through the next **30 days**; dates already in the past are never generated (no overdue noise). `work:generate-tasks` (scheduled daily 00:30) tops up the horizon. `(schedule, date)` is unique, so retries and reruns never duplicate. Bounded schedules become `ended` once fully generated. Nothing is generated while the cycle is closed or the breeding project is not active; it resumes after reopening.

## Templates

`source=platform` templates (seeded: `chicken-starter`, `crop-starter`, `chicken-incubation`) are read-only (`403 platform_template_readonly`); **clone** one to customise. Farm templates are versioned: each edit is `version + 1`; applied schedules are independent copies, so later edits never rewrite them. Template content is configuration, not veterinary or agronomic advice.

Item: `title`, `category`, `anchor` + `offset_days`, optional recurrence (`recurrence`, `interval_value`, `weekdays`, `until_offset_days`, `occurrence_limit`), `due_time`, `reminder_offsets`, `assigned_role`, `linked_record_type`, `requires_evidence`, `instructions`.

Anchors: `cycle_start`, `cycle_expected_end` (cycle templates); `breeding_start`, `breeding_expected` (exact date or window start), `breeding_expected_to` (exact date or window end) (breeding templates). Breeding anchors read the project's **stored** expectation; the biological reference and expectation are never changed. An item whose anchor has no date (e.g. no breeding expectation) is skipped (`skipped_no_anchor`).

Flow: `GET /work-templates/recommended?production_cycle_id=` (or `breeding_project_id=`) → user chooses **Apply**, **Clone → edit → apply** (customise), or ignores it (skip). Nothing applies automatically. `POST /work-templates/{id}/apply` is once per template and target (`409 template_already_applied`), requires an open cycle / active project, and is replay-safe by `idempotency_key`.

## Completion and evidence

1. Task has `linked_record_type` (an operational record type such as `feed_use`, or `health`, `breeding_check`, `breeding_outcome`).
2. `GET /tasks/{id}/record-prefill` → `{endpoint, prefill: {production_cycle_id, type, date, recorded_at}}`. Read-only.
3. The frontend saves the actual record at that endpoint (all its own validation, stock, population and idempotency rules apply).
4. `POST /tasks/{id}/complete` with `evidence: {type, id}`.

Evidence must belong to this farm, not be reversed, match the task's linked type, cycle and breeding project, and may evidence **one** task (`409 evidence_already_linked`). Evidence types: `operational_record`, `health_record`, `breeding_check`, `breeding_outcome`. `requires_evidence` tasks cannot complete without it (`422` on `evidence`). Completing twice with the same evidence returns the same task; different evidence is `409 task_already_completed`.

## Permissions and visibility

| Permission | Owner | Manager | Farm Worker | Vet | Finance |
|---|---|---|---|---|---|
| `task.view` | yes | yes | yes | yes | yes |
| `task.complete` | yes | yes | yes | yes | yes |
| `task.manage` (create/edit/cancel tasks, schedules, templates, apply) | yes | yes | no | no | no |

`task.manage` holders see every task. Others see tasks **assigned to them**, assigned to their **role**, created by them, plus their role's relevant categories (Vet: vaccination_medication, breeding_reproduction, growth_monitoring; Finance: payment, procurement_orders, record_keeping). Other tasks return `404`. Completing evidence also needs the matching view permission (`record.view`, `health.view`, `breeding.view`). Assignment: `assigned_user_id` must be an **active member** of the farm (`422` otherwise); `assigned_role` is a role name. Rate limit `work-write` 240/hour/user.

## Endpoints

| Method | URL | Permission |
|---|---|---|
| GET | `/master/task-categories` | task.view |
| GET | `/tasks` | task.view |
| POST | `/tasks` | task.manage |
| GET | `/tasks/{task}` | task.view |
| PATCH | `/tasks/{task}` | task.manage |
| POST | `/tasks/{task}/complete` | task.complete |
| POST | `/tasks/{task}/cancel` | task.manage |
| GET | `/tasks/{task}/record-prefill` | task.view |
| GET | `/schedules` | task.manage |
| POST | `/schedules` | task.manage |
| GET | `/schedules/{schedule}` | task.manage |
| POST | `/schedules/{schedule}/end` | task.manage |
| GET | `/calendar` | task.view |
| GET | `/work-templates` | task.view |
| GET | `/work-templates/recommended` | task.view |
| POST | `/work-templates` | task.manage |
| GET | `/work-templates/{template}` | task.view |
| PATCH | `/work-templates/{template}` | task.manage |
| POST | `/work-templates/{template}/clone` | task.manage |
| POST | `/work-templates/{template}/apply` | task.manage |

`GET /tasks` filters: `status`, `due_state`, `production_cycle_id`, `breeding_project_id`, `schedule_id`, `category`, `assigned_to` (`me` or user id), `from`, `to` (due date), `page`, `per_page`.

## Calendar

`GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD` (farm-local, max 92 days; optional `production_cycle_id`, `breeding_project_id`, `category`, `assigned_to`, `include_milestones=0`). `data` is a list ordered by date then time:

```json
{"kind": "task", "date": "2026-10-04", "end_date": null, "time": "06:00", "task": { "...TaskResource..." }}
{"kind": "milestone", "code": "breeding_expected", "title": "BRD-2026-00001 expected", "date": "2026-10-22", "end_date": null, "time": null,
 "source": {"type": "breeding_project", "id": "…", "reference": "BRD-2026-00001"}}
```

Milestone codes: `cycle_start`, `cycle_expected_end` (active cycles), `breeding_expected` (exact) / `breeding_expected_window` (`date`..`end_date`; active projects), `health_follow_up` (un-reversed `follow_up_on`). Milestones need the matching view permission and are omitted when filtering by `category` or `assigned_to`. They are not tasks and not records; `meta` carries `from`, `to`, `timezone`, `today`.

## Examples

Create a task (idempotent):

```json
POST /tasks
{"title": "Weigh a sample", "category": "growth_monitoring", "due_date": "2026-10-09", "due_time": "08:00",
 "production_cycle_id": "…", "linked_record_type": "weight", "assigned_user_id": "…", "idempotency_key": "6f1c…"}
```

Create a weekly schedule: `POST /schedules {"title": "Clean drinkers", "category": "cleaning_sanitation", "recurrence": "weekly", "weekdays": [1, 4], "starts_on": "2026-10-05", "production_cycle_id": "…", "idempotency_key": "…"}`.

Apply a template: `POST /work-templates/{id}/apply {"production_cycle_id": "…", "idempotency_key": "…"}` → `{schedules_created, tasks_created, skipped_past, skipped_no_anchor, template_version}`.

Complete with evidence: `POST /tasks/{id}/complete {"evidence": {"type": "operational_record", "id": "…"}, "note": "Done"}`.

## Errors

| Status / code | When |
|---|---|
| 422 `validation_failed` | invalid fields; `farm_id`/`status` sent; non-member assignee; evidence mismatch; missing required evidence; template does not fit the target; range > 92 days |
| 403 `forbidden`, `platform_template_readonly` | missing permission; editing a platform template |
| 404 `not_found` | unknown/foreign resource, or a task outside your visibility |
| 409 `idempotency_conflict` | key reused with a different payload |
| 409 `cycle_closed`, `project_not_active` | new work on a closed cycle / inactive project; completing on a closed cycle or cancelled project (cancel is still allowed) |
| 409 `task_not_open`, `task_already_completed`, `evidence_already_linked`, `task_has_no_linked_record` | lifecycle and evidence conflicts |
| 409 `template_already_applied`, `template_inactive` | template application |

## Not in this phase

Notification delivery (Phase 19; `reminder_offsets` are stored only), monthly/quarterly/yearly recurrence, editing a schedule in place (end it and create a new one), reopening a completed task, persisted "skip template" choices, and generating tasks automatically on cycle creation (the API recommends; the user applies).
