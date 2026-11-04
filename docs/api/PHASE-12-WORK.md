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

`recurrence`: `none` (one task on `starts_on`), `daily` (every `interval_value` days), `weekly` (every `interval_value` weeks on `weekdays`, ISO 1=Mon … 7=Sun; default the weekday of `starts_on`) or `monthly` (every `interval_value` months on the day number of `starts_on`; on the 29th-31st a shorter month uses its **last day** and the date never drifts: 31 Jan → 28 Feb → 31 Mar). Optional `ends_on` and/or `occurrence_limit` (counted from `starts_on`), or `ends_type: batch_end`. Quarterly/yearly rules are not supported (use monthly with `interval_value` 3 or 12).

`ends_type` (schedule field, always present in responses): `batch_end` = keep generating while the production cycle is open (needs a cycle or breeding project, no `ends_on` or `occurrence_limit`, else 422 `ends_type`); `date` = bounded by `ends_on` / `occurrence_limit`; `never`. **Closing the batch** (`POST /production-cycles/{id}/close`, and the system closing an emptied hatch batch) ends its `batch_end` schedules and cancels their open tasks due after today (`cancel_reason` = `Batch closed`); reopening the cycle does not restart them.

Tasks are generated for today through the next **30 days**; dates already in the past are never generated (no overdue noise). `work:generate-tasks` (scheduled daily 00:30) tops up the horizon. `(schedule, date)` is unique, so retries and reruns never duplicate. Bounded schedules become `ended` once fully generated. Nothing is generated while the cycle is closed or the breeding project is not active; it resumes after reopening.

## The single task form: `POST /tasks` with `repeat`

`POST /tasks` is the one entry point of the "Add task" form. Without `repeat` it creates one task (unchanged). With a `repeat` block it creates a schedule through the same validation and generation as `POST /schedules` and answers `201 {data: {schedule, tasks}}` (the schedule and the tasks generated so far). `POST /schedules` keeps working.

```json
"repeat": {"pattern": "daily|weekly|monthly", "every": 1, "weekdays": [1, 3], "ends": {"type": "days|date|batch_end|never", "days": 14, "date": "2026-12-31"}}
```

`due_date` is the first day. `every` 1-52. `weekdays` only for `weekly`. `ends.type`: `days` (needs `days` 1-3650; the last day is `due_date + days - 1`), `date` (needs `date` on or after `due_date`), `batch_end` (needs `production_cycle_id` or `breeding_project_id`; 422 `repeat.ends.type` otherwise) or `never` (default). The task fields (`title`, `category`, `instructions`, `due_time`, `reminders`, `reminder_offsets`, assignee, `linked_record_type`, `requires_evidence`, batch) apply to every generated task. The `idempotency_key` is the schedule's key (a replay returns the same schedule, a changed payload is `409 idempotency_conflict`). Full frontend flow: [`FRONTEND-TASKS-FLOW.md`](FRONTEND-TASKS-FLOW.md).

## Edit this and all future: `POST /tasks/{task}/edit-series`

Requires `task.manage`. Body: any of the task fields (`title`, `category`, `instructions`, `due_date` = the new first day, never earlier than this task, `due_time`, `reminders`, `reminder_offsets`, `assigned_user_id`, `assigned_role`, `linked_record_type`, `requires_evidence`), an optional `repeat` block (omitted = keep the old rule; an old `occurrence_limit` is reduced by the occurrences already used) and the required `idempotency_key`. One transaction: the old schedule is ended the day before this task's occurrence date; its **open** tasks from that date on that are due today or later are cancelled (`cancel_reason` `Series edited`; completed tasks are never touched and already-overdue open tasks are left alone); and a new schedule is created from this date with the changes (omitted fields carry over from this task). `201 {data: {schedule, tasks, previous_schedule_id}}`. Retrying with the same `idempotency_key` returns the same new schedule. `409 task_not_in_series` (the task has no schedule: use `PATCH /tasks/{id}`), `409 task_not_open`, `409 cycle_closed` / `project_not_active`, `409 idempotency_conflict`. "This task only" stays `PATCH /tasks/{id}`.

## Reminders

`reminders` (task, schedule and `POST /tasks` field): any of `on_the_day`, `1_day`, `2_days`, `7_days`. They fire at **07:00 farm-local** on the due day minus 0 / 1 / 2 / 7 days; `on_the_day` on a task with a `due_time` fires **no later than 1 hour before** it (never before 00:00). `reminder_offsets` (minutes before the due instant) still works and may be combined.

Reminders are delivered by `notifications:generate` (hourly) through the notification system (type `task_reminder`, in-app and e-mail; the user's per-type and channel preferences apply):

* **Recipients:** the assignee, or every active member with the assigned role; a task with **no assignee and no role** reminds its **creator**.
* **One reminder per task, due date and reminder:** the dedupe key is `task:{id}:{due_date}:remind:{preset|m<minutes>}`, so a task that is moved to another day is reminded again.
* Only the tightest reminder that has passed (while the task is not yet due) is sent. Presets whose fire time was already in the past when the task was created are never sent, so a new series does not announce a whole week at once (minute offsets keep their old behaviour).
* The notification title is `Reminder: {task title}`; the message names the task, the date/time in the farm timezone (or "all day") and the batch; `data` has `due_date`, `due_time`, `timezone`, `batch`, `reminder`, `reminder_minutes_before`. The e-mail links to `{FRONTEND_URL}/tasks/{task id}`.

## Templates

`source=platform` templates (seeded: `chicken-starter`, `crop-starter`, `chicken-incubation`) are read-only (`403 platform_template_readonly`); **clone** one to customise. Farm templates are versioned: each edit is `version + 1`; applied schedules are independent copies, so later edits never rewrite them. Template content is configuration, not veterinary or agronomic advice.

Item: `title`, `category`, `anchor` + `offset_days`, optional recurrence (`recurrence` incl. `monthly`, `interval_value`, `weekdays`, `until_offset_days`, `occurrence_limit`), `due_time`, `reminder_offsets`, `assigned_role`, `linked_record_type`, `requires_evidence`, `instructions`.

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
| GET | `/master/task-categories` | task.view (each category has `description` and `color_group`) |
| GET | `/tasks` | task.view |
| POST | `/tasks` | task.manage |
| GET | `/tasks/{task}` | task.view |
| PATCH | `/tasks/{task}` | task.manage |
| POST | `/tasks/{task}/complete` | task.complete |
| POST | `/tasks/{task}/cancel` | task.manage |
| POST | `/tasks/{task}/edit-series` | task.manage |
| GET | `/tasks/{task}/record-prefill` | task.view |
| GET | `/schedules` | task.manage |
| POST | `/schedules` | task.manage |
| GET | `/schedules/{schedule}` | task.manage |
| POST | `/schedules/{schedule}/end` | task.manage |
| GET | `/calendar` | task.view |
| GET | `/calendar-feed` | task.view |
| POST | `/calendar-feed` | task.view |
| DELETE | `/calendar-feed` | task.view |
| GET | `/calendar-feed/{token}.ics` | public (secret token) |
| GET | `/work-templates` | task.view |
| GET | `/work-templates/recommended` | task.view |
| POST | `/work-templates` | task.manage |
| GET | `/work-templates/{template}` | task.view |
| PATCH | `/work-templates/{template}` | task.manage |
| POST | `/work-templates/{template}/clone` | task.manage |
| POST | `/work-templates/{template}/apply` | task.manage |

`GET /tasks` filters: `status`, `due_state`, `production_cycle_id`, `breeding_project_id`, `schedule_id`, `category`, `assigned_to` (`me` or user id), `from`, `to` (due date), `page`, `per_page`.

## Calendar

`GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD` (farm-local, max 92 days; optional `production_cycle_id`, `breeding_project_id`, `category`, `assigned_to`, `include_milestones=0`, `include_upcoming_repeats=0`). `data` is a list ordered by date then time:

```json
{"kind": "task", "date": "2026-10-04", "end_date": null, "time": "06:00", "task": { "...TaskResource..." }}
{"kind": "milestone", "code": "breeding_expected", "title": "BRD-2026-00001 expected", "date": "2026-10-22", "end_date": null, "time": null,
 "source": {"type": "breeding_project", "id": "…", "reference": "BRD-2026-00001"}}
```

```json
{"kind": "upcoming_repeat", "date": "2026-11-20", "end_date": null, "time": null, "title": "Weigh a sample of 20 birds", "category": "growth_monitoring",
 "schedule_id": "…", "production_cycle_id": "…", "breeding_project_id": null}
```

`upcoming_repeat` items are the **coming repeats**: future occurrences of active schedules that have no task yet (beyond the generated 30-day horizon), computed from the rule with the same engine as generation and never stored. They follow the same range (max 92 days), filters, per-role visibility and batch state as tasks (a schedule whose cycle/project is not open is not listed), skip dates that already have a task (including cancelled ones), and are not completable: they have no `id`.

Milestone codes: `cycle_start`, `cycle_expected_end` (active cycles), `breeding_expected` (exact) / `breeding_expected_window` (`date`..`end_date`; active projects), `health_follow_up` (un-reversed `follow_up_on`). Milestones need the matching view permission and are omitted when filtering by `category` or `assigned_to`. They are not tasks and not records; `meta` carries `from`, `to`, `timezone`, `today`.

## Task categories

`GET /master/task-categories` → `[{code, label, description, color_group}]`. `description` is a short plain-language help text; `color_group` is one of `feeding_eggs_growth` (feeding_watering, egg_collection, growth_monitoring), `health_vaccines` (vaccination_medication), `breeding` (breeding_reproduction), `cleaning_repairs_moves` (cleaning_sanitation, maintenance_repair, movement_rotation), `crops` (irrigation, crop_care, harvest), `orders_records_other` (record_keeping, procurement_orders, payment, other), so the frontend can colour the calendar without hard-coding categories.

## Phone calendar link (one-way ICS subscription)

Per user and farm, a secret random token (stored as a SHA-256 hash for lookup, plus an encrypted copy so the link can be shown again).

| Call | Result |
|---|---|
| `GET /calendar-feed` (task.view) | `{enabled, include_milestones, https_url, webcal_url}`; the URLs are `null` when off |
| `POST /calendar-feed` (task.view) `{include_milestones?, regenerate?}` | Turns the feed on (`201`, new link). When already on: `200` and the link is kept unless `regenerate: true` (`201`, new link; the old one stops working at once). `include_milestones` adds the cycle, breeding and health follow-up milestones the user may see. |
| `DELETE /calendar-feed` (task.view) | Turns it off (the link answers 404 at once; a later `POST` issues a different link). Idempotent. |
| `GET /calendar-feed/{token}.ics` (public) | No session; the token is the credential; rate limited (30/min/IP). `text/calendar` iCalendar feed; `404` for an unknown or switched-off token, or when the member's membership is not active, the user is suspended or no longer has `task.view`. |

Feed content: the member's visible tasks (the same visibility as `GET /calendar`) from **30 days back to 180 days ahead**, plus the coming repeats, and optionally milestones. Cancelled tasks are excluded; completed tasks get a tick in the title and no alarm. Each event has a stable `UID` (`task-{id}` for single tasks, `occ-{schedule id}-{date}` for repeat occurrences, so a coming repeat keeps its UID when it becomes a task; `ms-…` for milestones), `SUMMARY`, `CATEGORIES`, `X-BATCH`, a `DESCRIPTION` with category, batch and a link, and `URL` = `{FRONTEND_URL}/tasks/{id}`. It is **all-day** when the task has no `due_time`, otherwise a 30-minute event with `TZID` = the farm timezone (a `VTIMEZONE` is included). One `VALARM` per reminder (the same fire times as the e-mail reminders). Text is escaped and lines are folded at 75 octets with CRLF endings, never inside a UTF-8 character. The token is a bearer secret: anyone holding the link can read that user's tasks, so present it as private.

## Examples

Create a task (idempotent):

```json
POST /tasks
{"title": "Weigh a sample", "category": "growth_monitoring", "due_date": "2026-10-09", "due_time": "08:00",
 "production_cycle_id": "…", "linked_record_type": "weight", "assigned_user_id": "…", "idempotency_key": "6f1c…"}
```

Create a repeating task (single form): `POST /tasks {"title": "Clean drinkers", "category": "cleaning_sanitation", "due_date": "2026-10-05", "production_cycle_id": "…", "reminders": ["1_day"], "repeat": {"pattern": "weekly", "weekdays": [1, 4], "ends": {"type": "batch_end"}}, "idempotency_key": "…"}` → `201 {schedule, tasks}`.

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
| 409 `task_not_in_series` | `POST /tasks/{id}/edit-series` on a task that does not repeat |
| 409 `task_not_open`, `task_already_completed`, `evidence_already_linked`, `task_has_no_linked_record` | lifecycle and evidence conflicts |
| 409 `template_already_applied`, `template_inactive` | template application |

## Not in this phase

Quarterly/yearly rules (use monthly with `interval_value` 3 or 12), editing a schedule in place (use "edit this and all future", which ends it and starts a new one), edits to single occurrences surviving a series edit, reopening a completed task, persisted "skip template" choices, and generating tasks automatically on cycle creation (the API recommends; the user applies).
