# Frontend flow: tasks, repeats, calendar, reminders and the phone calendar link

How the frontend builds the **Add task** form, the **calendar**, **Done / Skip / Edit**, **reminders** and **Add to my phone calendar** on top of the Work API
(Phase 12). Endpoint reference: [`PHASE-12-WORK.md`](PHASE-12-WORK.md). All paths are under `/api/v1`, farm-local dates are `YYYY-MM-DD`, times are `HH:MM`
in the **farm's timezone** (default `Africa/Lagos`; returned as `timezone`). Every example below is a real response captured from the running application.

Write endpoints are rate limited (240/hour/user). Every create needs an `idempotency_key` (8-80 characters): generate one when the form opens and reuse it if the
user taps Save twice or you retry, so nothing is created twice.

---

## 1. The "Add task" form

One form, one endpoint: `POST /tasks`. Without a `repeat` block it creates **one task**; with a `repeat` block it creates a **repeating series** (a schedule) and
the tasks that are due in the next 30 days. Needs `task.manage` (owner, manager); anyone else gets `403 forbidden`: hide the button when
`membership.permissions` has no `task.manage`.

### Fields

| Form field | JSON field | Notes |
|---|---|---|
| What needs doing | `title` (required, max 200) | |
| Details | `instructions` (max 5000) | optional |
| Category | `category` (required) | Offer the list from `GET /master/task-categories` (section 3). |
| Date | `due_date` (required) | Farm-local. Past dates are allowed (the task is simply overdue). For a repeat this is the **first** day. |
| Time (optional) | `due_time` | `HH:MM`. Leave empty for an all-day task (due by the end of the day). |
| Batch (optional) | `production_cycle_id` or `breeding_project_id` | Required when "until the batch ends" is chosen. Work can only be added to an **open** batch (`409 cycle_closed`). |
| Assign to (optional) | `assigned_user_id` or `assigned_role` | An active member of the farm. |
| Remind me | `reminders` | Any of `on_the_day`, `1_day`, `2_days`, `7_days` (see section 6). |
| Repeat | `repeat` | See below. Omit it for a single task. |

(`linked_record_type` / `requires_evidence` are unchanged: they say which real record will evidence the task, see `PHASE-12-WORK.md`.)

### The Repeat block

```json
"repeat": {
  "pattern": "daily | weekly | monthly",
  "every": 1,
  "weekdays": [1, 3],
  "ends": { "type": "days | date | batch_end | never", "days": 14, "date": "2026-12-31" }
}
```

| Option in the UI | Send |
|---|---|
| Every day | `pattern: "daily"` |
| Every 3 days | `pattern: "daily", every: 3` |
| Every week on Mon + Wed | `pattern: "weekly", weekdays: [1, 3]` (1 = Monday ... 7 = Sunday; without `weekdays` it uses the weekday of `due_date`) |
| Every 2 weeks | `pattern: "weekly", every: 2` |
| Every month | `pattern: "monthly"`: same day number each month; on the 29th-31st shorter months use their **last day** (31 Jan, 28 Feb, 31 Mar...) |
| Every 3 months | `pattern: "monthly", every: 3` |
| Keep doing this for 14 days | `ends: {type: "days", days: 14}`: the last day is `due_date + 13` |
| Until a date | `ends: {type: "date", date: "2026-12-31"}` |
| Until the batch ends | `ends: {type: "batch_end"}` (needs a batch). Runs while the batch is open; when the batch is **closed** the series ends and its future open tasks are cancelled ("Batch closed"). Reopening the batch does not restart it. |
| No end | `ends: {type: "never"}` (or leave `ends` out) |

`every` is 1-52. `weekdays` is only valid for `weekly`.

### Single task: request and response

`POST /tasks`

```json
{
    "title": "Check drinkers",
    "category": "feeding_watering",
    "due_date": "2026-10-15",
    "idempotency_key": "form-7f3a91c2-0001",
    "due_time": "07:30",
    "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
    "reminders": [
        "on_the_day",
        "1_day"
    ]
}
```

`201`, `data` is the task:

```json
{
    "data": {
        "id": "01a1a755-4636-71b9-8fb5-3d7632c8d130",
        "reference": "TSK-2026-00001",
        "status": "open",
        "due_state": "upcoming",
        "title": "Check drinkers",
        "category": "feeding_watering",
        "instructions": null,
        "due_date": "2026-10-15",
        "due_time": "07:30",
        "due_at": "2026-10-15T06:30:00.000000Z",
        "timezone": "Africa/Lagos",
        "reminder_offsets": null,
        "reminders": [
            "on_the_day",
            "1_day"
        ],
        "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
        "breeding_project_id": null,
        "schedule_id": null,
        "occurrence_date": null,
        "assigned_user_id": null,
        "assigned_user_name": null,
        "assigned_role": null,
        "linked_record_type": null,
        "requires_evidence": false,
        "completion": null,
        "cancellation": null,
        "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Task created."
}
```

### Repeating task: request and response

A weekly Friday job for as long as the batch is open:

```json
{
    "title": "Weigh a sample of 20 birds",
    "category": "growth_monitoring",
    "due_date": "2026-10-16",
    "idempotency_key": "form-7f3a91c2-0002",
    "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
    "reminders": [
        "1_day"
    ],
    "repeat": {
        "pattern": "weekly",
        "every": 1,
        "weekdays": [
            5
        ],
        "ends": {
            "type": "batch_end"
        }
    }
}
```

`201`, `data` is `{schedule, tasks}`: the **schedule** (the repeat rule) and the **tasks** generated so far (today through the next 30 days, never past dates; the
response below shows the first of the generated tasks). Check `data.schedule` to tell it apart from a single task. The rest of the series appears on the calendar
as *coming repeats* (section 5) and is turned into real tasks automatically every night.

```json
{
    "data": {
        "schedule": {
            "id": "01a1a755-483d-731c-b883-8b0a2528b65f",
            "status": "active",
            "title": "Weigh a sample of 20 birds",
            "category": "growth_monitoring",
            "instructions": null,
            "recurrence": "weekly",
            "interval_value": 1,
            "weekdays": [5],
            "starts_on": "2026-10-16",
            "ends_on": null,
            "occurrence_limit": null,
            "ends_type": "batch_end",
            "due_time": null,
            "reminder_offsets": null,
            "reminders": ["1_day"],
            "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
            "breeding_project_id": null,
            "template_application_id": null,
            "assigned_user_id": null,
            "assigned_role": null,
            "linked_record_type": null,
            "requires_evidence": false,
            "tasks_count": 5,
            "open_tasks_count": 5,
            "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
            "created_at": "2026-10-14T11:00:00.000000Z"
        },
        "tasks": [
            {
                "id": "01a1a755-48af-71f0-a201-79520eb20958",
                "reference": "TSK-2026-00002",
                "status": "open",
                "due_state": "upcoming",
                "title": "Weigh a sample of 20 birds",
                "category": "growth_monitoring",
                "instructions": null,
                "due_date": "2026-10-16",
                "due_time": null,
                "due_at": "2026-10-16T23:00:00.000000Z",
                "timezone": "Africa/Lagos",
                "reminder_offsets": null,
                "reminders": ["1_day"],
                "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
                "breeding_project_id": null,
                "schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f",
                "occurrence_date": "2026-10-16",
                "assigned_user_id": null,
                "assigned_user_name": null,
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false,
                "completion": null,
                "cancellation": null,
                "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
                "created_at": "2026-10-14T11:00:00.000000Z"
            }
        ]
    },
    "meta": [],
    "message": "Repeating task created."
}
```

A monthly payment reminder for a year:

```json
{
    "title": "Pay feed supplier",
    "category": "payment",
    "due_date": "2026-10-31",
    "idempotency_key": "form-7f3a91c2-0003",
    "repeat": {
        "pattern": "monthly",
        "every": 1,
        "ends": {
            "type": "days",
            "days": 365
        }
    }
}
```

```json
{
    "data": {
        "schedule": {
            "id": "01a1a755-4bb1-728f-93a2-f8290d23a5ef",
            "status": "active",
            "title": "Pay feed supplier",
            "category": "payment",
            "instructions": null,
            "recurrence": "monthly",
            "interval_value": 1,
            "weekdays": null,
            "starts_on": "2026-10-31",
            "ends_on": "2027-10-30",
            "occurrence_limit": null,
            "ends_type": "date",
            "due_time": null,
            "reminder_offsets": null,
            "reminders": null,
            "production_cycle_id": null,
            "breeding_project_id": null,
            "template_application_id": null,
            "assigned_user_id": null,
            "assigned_role": null,
            "linked_record_type": null,
            "requires_evidence": false,
            "tasks_count": 1,
            "open_tasks_count": 1,
            "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
            "created_at": "2026-10-14T11:00:00.000000Z"
        },
        "tasks": [
            {
                "id": "01a1a755-4bfd-71fe-8b92-dafa56eeda37",
                "reference": "TSK-2026-00007",
                "status": "open",
                "due_state": "upcoming",
                "title": "Pay feed supplier",
                "category": "payment",
                "instructions": null,
                "due_date": "2026-10-31",
                "due_time": null,
                "due_at": "2026-10-31T23:00:00.000000Z",
                "timezone": "Africa/Lagos",
                "reminder_offsets": null,
                "reminders": null,
                "production_cycle_id": null,
                "breeding_project_id": null,
                "schedule_id": "01a1a755-4bb1-728f-93a2-f8290d23a5ef",
                "occurrence_date": "2026-10-31",
                "assigned_user_id": null,
                "assigned_user_name": null,
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false,
                "completion": null,
                "cancellation": null,
                "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
                "created_at": "2026-10-14T11:00:00.000000Z"
            }
        ]
    },
    "meta": [],
    "message": "Repeating task created."
}
```

### Errors on the form

`422 validation_failed`: `errors` is keyed by field; nested fields use dots (`repeat.ends.type`, `reminders.0`). Show each message under its field.

```json
{
    "message": "Until the batch ends needs a batch (production_cycle_id or breeding_project_id).",
    "code": "validation_failed",
    "request_id": "01a1a755-0000-7000-8000-000000000000",
    "errors": {
        "repeat.ends.type": ["Until the batch ends needs a batch (production_cycle_id or breeding_project_id)."]
    }
}
```

```json
{
    "message": "The selected repeat.pattern is invalid. (and 1 more error)",
    "code": "validation_failed",
    "request_id": "01a1a755-0000-7000-8000-000000000000",
    "errors": {
        "repeat.pattern": ["The selected repeat.pattern is invalid."],
        "reminders.0": ["The selected reminders.0 is invalid."]
    }
}
```

Other statuses: `403 forbidden` (no `task.manage`), `409 cycle_closed` / `project_not_active` (the batch is closed or the project is not active),
`409 idempotency_conflict` (same key, different data), `429 too_many_requests`.

### The "Add to my phone calendar" prompt after the first task

After the user creates their **first** task, show a small prompt "Add your tasks to your phone calendar?". Call `GET /calendar-feed` first: if `enabled` is already
`true` do not ask. On yes go to section 7. (Remember the answer, for example in `localStorage`, so you do not ask again.)

---

## 2. Tapping a day on the calendar

`GET /calendar?from=2026-10-16&to=2026-10-16` (same date twice for one day; the longest range is 92 days, otherwise `422`). Needs `task.view`. The response is
a flat list ordered by date, then time:

| `kind` | What it is | Show as |
|---|---|---|
| `task` | A real task (`task` holds the full task, with `due_state`: `upcoming`, `due_today`, `overdue`, `completed`, `cancelled`) | A row with Done / Skip / Edit |
| `upcoming_repeat` | A **coming repeat**: a future occurrence of a repeating series that is not a task yet | A lighter, read-only row ("Repeats weekly") |
| `milestone` | A read-only date from the farm (cycle start / expected end, breeding expectation, health follow-up) | A flag, not a task |

Optional filters: `production_cycle_id`, `breeding_project_id`, `category`, `assigned_to` (`me` or a member id), `include_milestones=false`,
`include_upcoming_repeats=false`. Milestones are left out when you filter by category or assignee.

```json
{
    "data": [
        {
            "kind": "task",
            "date": "2026-10-16",
            "end_date": null,
            "time": null,
            "task": {
                "id": "01a1a755-48af-71f0-a201-79520eb20958",
                "reference": "TSK-2026-00002",
                "status": "open",
                "due_state": "upcoming",
                "title": "Weigh a sample of 20 birds",
                "category": "growth_monitoring",
                "instructions": null,
                "due_date": "2026-10-16",
                "due_time": null,
                "due_at": "2026-10-16T23:00:00.000000Z",
                "timezone": "Africa/Lagos",
                "reminder_offsets": null,
                "reminders": ["1_day"],
                "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
                "breeding_project_id": null,
                "schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f",
                "occurrence_date": "2026-10-16",
                "assigned_user_id": null,
                "assigned_user_name": null,
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false,
                "completion": null,
                "cancellation": null,
                "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
                "created_at": "2026-10-14T11:00:00.000000Z"
            }
        }
    ],
    "meta": {
        "from": "2026-10-16",
        "to": "2026-10-16",
        "timezone": "Africa/Lagos",
        "today": "2026-10-14"
    },
    "message": null
}
```

The month grid: request `from`=first visible day, `to`=last visible day (a 6-week month view is 42 days), group the items by `date`, and use `meta.today` for the
"today" highlight (it is the farm's day, not the phone's). Cancelled tasks are returned too: grey them out or hide them.

## 3. Colours and category help

`GET /master/task-categories` returns, for each category, a plain-language `description` (use it as help text in the category picker) and a `color_group`:

| `color_group` | Categories |
|---|---|
| `feeding_eggs_growth` | feeding_watering, egg_collection, growth_monitoring |
| `health_vaccines` | vaccination_medication |
| `breeding` | breeding_reproduction |
| `cleaning_repairs_moves` | cleaning_sanitation, maintenance_repair, movement_rotation |
| `crops` | irrigation, crop_care, harvest |
| `orders_records_other` | record_keeping, procurement_orders, payment, other |

Pick one colour per group and look the group up from the item's `category` (tasks, coming repeats and the calendar filter all carry `category`). Do not
hard-code the category list: it comes from this endpoint. (First entries shown; the real response lists all 15.)

```json
{
    "data": [
        {
            "code": "feeding_watering",
            "label": "Feeding / watering",
            "description": "Giving feed and clean water to your animals.",
            "color_group": "feeding_eggs_growth"
        },
        {
            "code": "egg_collection",
            "label": "Egg collection",
            "description": "Collecting eggs from the laying houses or nests.",
            "color_group": "feeding_eggs_growth"
        },
        {
            "code": "vaccination_medication",
            "label": "Vaccination / medication",
            "description": "Vaccinating or treating animals, including follow-up doses.",
            "color_group": "health_vaccines"
        },
        {
            "code": "breeding_reproduction",
            "label": "Breeding / reproduction",
            "description": "Mating, egg setting, candling, hatching and birthing checks.",
            "color_group": "breeding"
        },
        {
            "code": "growth_monitoring",
            "label": "Growth monitoring",
            "description": "Weighing a sample or checking how well the animals are growing.",
            "color_group": "feeding_eggs_growth"
        },
        {
            "code": "record_keeping",
            "label": "Record keeping",
            "description": "Writing down counts, notes or measurements so your records stay complete.",
            "color_group": "orders_records_other"
        },
        {
            "code": "maintenance_repair",
            "label": "Maintenance / repair",
            "description": "Fixing or servicing pens, equipment, pumps and buildings.",
            "color_group": "cleaning_repairs_moves"
        },
        {
            "code": "cleaning_sanitation",
            "label": "Cleaning / sanitation",
            "description": "Cleaning houses, drinkers, feeders and footbaths.",
            "color_group": "cleaning_repairs_moves"
        },
        {
            "code": "movement_rotation",
            "label": "Movement / rotation",
            "description": "Moving animals between houses or pens, or rotating pasture.",
            "color_group": "cleaning_repairs_moves"
        },
        {
            "code": "procurement_orders",
            "label": "Procurement / orders",
            "description": "Buying or ordering feed, medicine, seed and other supplies.",
            "color_group": "orders_records_other"
        },
        {
            "code": "irrigation",
            "label": "Irrigation",
            "description": "Watering crops by hand, drip or sprinkler.",
            "color_group": "crops"
        },
        {
            "code": "crop_care",
            "label": "Crop care",
            "description": "Weeding, spraying, pruning and checking crops for pests.",
            "color_group": "crops"
        },
        {
            "code": "harvest",
            "label": "Harvest",
            "description": "Picking or cutting crops when they are ready.",
            "color_group": "crops"
        },
        {
            "code": "payment",
            "label": "Payment",
            "description": "Paying a bill, wages or a supplier, or collecting money owed.",
            "color_group": "orders_records_other"
        },
        {
            "code": "other",
            "label": "Other",
            "description": "Any other job that does not fit the groups above.",
            "color_group": "orders_records_other"
        }
    ],
    "meta": [],
    "message": null
}
```

## 4. Colour and state of a task row

Use `task.due_state`: `overdue` (red accent), `due_today`, `upcoming`, `completed` (tick, muted), `cancelled` (struck through). `due_at` is the UTC instant after
which an open task is overdue; always display `due_date` / `due_time` (already farm-local), never convert `due_at` yourself.

## 5. Coming repeats

An `upcoming_repeat` item is computed from the rule on the fly and is **not stored**: it has `title`, `category`, `schedule_id`, `date`, `time` (null when
all-day) and the batch ids, but no `id` of its own and nothing to complete. It disappears from the list on the night it becomes a real task, and tasks
that were skipped (cancelled) never come back. The same filters and visibility as tasks apply, and a repeat whose batch is closed is not listed.

To let the user stop or change a repeat from a coming-repeat row, open the next real task of the same `schedule_id` (`GET /tasks?schedule_id=...&status=open`)
and use "Edit this and all future" (section 6), or `POST /schedules/{id}/end` to end the series.

```json
{
    "data": [
        {
            "kind": "upcoming_repeat",
            "date": "2026-11-20",
            "end_date": null,
            "time": null,
            "title": "Weigh a sample of 20 birds",
            "category": "growth_monitoring",
            "schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f",
            "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
            "breeding_project_id": null
        },
        {
            "kind": "upcoming_repeat",
            "date": "2026-11-27",
            "end_date": null,
            "time": null,
            "title": "Weigh a sample of 20 birds",
            "category": "growth_monitoring",
            "schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f",
            "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
            "breeding_project_id": null
        },
        {
            "kind": "upcoming_repeat",
            "date": "2026-12-04",
            "end_date": null,
            "time": null,
            "title": "Weigh a sample of 20 birds",
            "category": "growth_monitoring",
            "schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f",
            "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
            "breeding_project_id": null
        }
    ],
    "meta": {
        "from": "2026-11-20",
        "to": "2026-12-05",
        "timezone": "Africa/Lagos",
        "today": "2026-10-14"
    },
    "message": null
}
```

---

## 6. Done, Skip and Edit

| Button | Call | Needs |
|---|---|---|
| **Done** | `POST /tasks/{id}/complete` `{note?, completed_at?, evidence?}` | `task.complete` |
| **Skip** | `POST /tasks/{id}/cancel` `{reason}` | `task.manage` |
| **Edit: this task only** | `PATCH /tasks/{id}` | `task.manage` |
| **Edit: this and all future** | `POST /tasks/{id}/edit-series` | `task.manage` |

Only **open** tasks can be edited or skipped (`409 task_not_open`). Completing a task never creates a record by itself; if the task has a `linked_record_type`
use `GET /tasks/{id}/record-prefill` to open the right form first (unchanged, see `PHASE-12-WORK.md`).

**Done**

```json
{
    "data": {
        "id": "01a1a755-48af-71f0-a201-79520eb20958",
        "reference": "TSK-2026-00002",
        "status": "completed",
        "due_state": "completed",
        "title": "Weigh a sample of 20 birds",
        "category": "growth_monitoring",
        "instructions": null,
        "due_date": "2026-10-16",
        "due_time": null,
        "due_at": "2026-10-16T23:00:00.000000Z",
        "timezone": "Africa/Lagos",
        "reminder_offsets": null,
        "reminders": ["1_day"],
        "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
        "breeding_project_id": null,
        "schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f",
        "occurrence_date": "2026-10-16",
        "assigned_user_id": null,
        "assigned_user_name": null,
        "assigned_role": null,
        "linked_record_type": null,
        "requires_evidence": false,
        "completion": {
            "completed_at": "2026-10-14T11:00:00.000000Z",
            "completed_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
            "note": "Average 1.9 kg",
            "evidence": null
        },
        "cancellation": null,
        "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Task completed."
}
```

**Skip** (a skipped occurrence stays in history as `cancelled` with its reason and is not regenerated):

```json
{
    "data": {
        "id": "01a1a755-5574-73da-aead-9e8a08b6bb54",
        "reference": "TSK-2026-00008",
        "status": "cancelled",
        "due_state": "cancelled",
        "title": "Clean footbath",
        "category": "cleaning_sanitation",
        "instructions": null,
        "due_date": "2026-10-15",
        "due_time": null,
        "due_at": "2026-10-15T23:00:00.000000Z",
        "timezone": "Africa/Lagos",
        "reminder_offsets": null,
        "reminders": null,
        "production_cycle_id": null,
        "breeding_project_id": null,
        "schedule_id": null,
        "occurrence_date": null,
        "assigned_user_id": null,
        "assigned_user_name": null,
        "assigned_role": null,
        "linked_record_type": null,
        "requires_evidence": false,
        "completion": null,
        "cancellation": {
            "cancelled_at": "2026-10-14T11:00:00.000000Z",
            "reason": "Skipped: house empty"
        },
        "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Task cancelled."
}
```

**Edit this task only**: only the fields you send change (`title`, `category`, `instructions`, `due_date`, `due_time`, `reminders`, `reminder_offsets`,
`assigned_user_id`, `assigned_role`, `linked_record_type`, `requires_evidence`). The task stays part of its series.

```json
{
    "due_time": "09:00"
}
```

**Edit this and all future**: show this choice whenever the task has a `schedule_id`. The task id you call is the first occurrence that changes.

What happens, in one transaction:

1. The old series ends the day before this task's date.
2. Its open tasks from this date on are cancelled (reason `Series edited`). Completed tasks are never touched; open tasks that are already overdue stay as they are.
3. A new series starts from this date with your changes. Fields you do not send carry over from this task; send `repeat` to change the pattern too.

```json
{
    "title": "Weigh a sample of 30 birds",
    "due_time": "08:00",
    "idempotency_key": "form-7f3a91c2-0006"
}
```

`201`, `data` is `{schedule, tasks, previous_schedule_id}` (the new schedule, its generated tasks, and the schedule that was ended). The same
`idempotency_key` returns the same new series, so a retry is safe. `due_date` may move the start later, never earlier than this task.

```json
{
    "data": {
        "schedule": {
            "id": "01a1a755-5880-735a-ba59-4a9381cf79ab",
            "status": "active",
            "title": "Weigh a sample of 30 birds",
            "category": "growth_monitoring",
            "instructions": null,
            "recurrence": "weekly",
            "interval_value": 1,
            "weekdays": [5],
            "starts_on": "2026-10-23",
            "ends_on": null,
            "occurrence_limit": null,
            "ends_type": "batch_end",
            "due_time": "08:00",
            "reminder_offsets": null,
            "reminders": ["1_day"],
            "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
            "breeding_project_id": null,
            "template_application_id": null,
            "assigned_user_id": null,
            "assigned_role": null,
            "linked_record_type": null,
            "requires_evidence": false,
            "tasks_count": 4,
            "open_tasks_count": 4,
            "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
            "created_at": "2026-10-14T11:00:00.000000Z"
        },
        "tasks": [
            {
                "id": "01a1a755-58c2-714c-bbbc-3b5361a74b4b",
                "reference": "TSK-2026-00009",
                "status": "open",
                "due_state": "upcoming",
                "title": "Weigh a sample of 30 birds",
                "category": "growth_monitoring",
                "instructions": null,
                "due_date": "2026-10-23",
                "due_time": "08:00",
                "due_at": "2026-10-23T07:00:00.000000Z",
                "timezone": "Africa/Lagos",
                "reminder_offsets": null,
                "reminders": ["1_day"],
                "production_cycle_id": "01a1a755-424d-701d-a75a-07d5d83779ba",
                "breeding_project_id": null,
                "schedule_id": "01a1a755-5880-735a-ba59-4a9381cf79ab",
                "occurrence_date": "2026-10-23",
                "assigned_user_id": null,
                "assigned_user_name": null,
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false,
                "completion": null,
                "cancellation": null,
                "created_by": "01a1a755-3d26-7229-8f97-5e82ff1eba71",
                "created_at": "2026-10-14T11:00:00.000000Z"
            }
        ],
        "previous_schedule_id": "01a1a755-483d-731c-b883-8b0a2528b65f"
    },
    "meta": [],
    "message": "Series updated."
}
```

Errors: `409 task_not_in_series` (a single task: use PATCH instead), `409 task_not_open`, `409 cycle_closed`, `409 idempotency_conflict`, `403 forbidden`, `422`.

```json
{
    "message": "This task does not repeat; edit it directly.",
    "code": "task_not_in_series",
    "request_id": "01a1a755-0000-7000-8000-000000000000"
}
```

---

## 7. Reminders

Send `reminders` (any of the four) on create and edit; `reminder_offsets` (minutes before due) still works for older clients. They are returned as `reminders` and
`reminder_offsets`.

| Preset | Fires |
|---|---|
| `on_the_day` | 07:00 farm-local on the due day; if the task has a `due_time` it fires **no later than 1 hour before** it (due 07:30 -> reminder 06:30; never before 00:00) |
| `1_day` | 07:00 the day before |
| `2_days` | 07:00 two days before |
| `7_days` | 07:00 seven days before |

Rules to know:

* **Who is reminded:** the assignee, or everyone with the assigned role; if the task has **no assignee and no role**, its **creator**.
* A reminder is sent once per task, per due date: if the task is **moved** to another day it is reminded again for the new date.
* A reminder time that was already in the past when the task was created is not sent (a new series does not announce its whole first week at once).
* Reminders arrive in the in-app list (`GET /notifications`, type `task_reminder`) and by email when the user's email channel is on. Users switch the
  `task_reminder` type on or off in notification settings. Reminders are checked **hourly**, so one can arrive up to an hour after its time.

The in-app notification (`source` links to the task):

```json
{
    "data": [
        {
            "id": "01a1a755-5f40-7209-aea0-4c561379e2b2",
            "type": "task_reminder",
            "severity": "info",
            "title": "Reminder: Candle eggs",
            "message": "Candle eggs is due Tue 20 Oct, 14:00 (Africa/Lagos). Batch: Layers — House 2.",
            "source": {
                "type": "task",
                "id": "01a1a755-5d8f-73e2-b6a9-cdfa8da9b004",
                "reference": "TSK-2026-00014"
            },
            "data": {
                "batch": "Layers — House 2",
                "due_at": "2026-10-20T13:00:00.000000Z",
                "due_date": "2026-10-20",
                "due_time": "14:00",
                "reminder": "on_the_day",
                "timezone": "Africa/Lagos",
                "reminder_minutes_before": 420
            },
            "is_read": false,
            "read_at": null,
            "created_at": "2026-10-14T11:00:00.000000Z"
        }
    ],
    "meta": {
        "current_page": 1,
        "per_page": 25,
        "last_page": 1,
        "total": 1,
        "unread_count": 1
    },
    "message": null
}
```

The email says what, which batch and when (in the farm timezone) and links to `{FRONTEND_URL}/tasks/{task id}`; make sure that route opens the task:

```
Subject: Green Acres: Reminder: Candle eggs

Hello,
Candle eggs is due Tue 20 Oct, 14:00 (Africa/Lagos). Batch: Layers — House 2.
[Open task] https://app.farmvest.example/tasks/01a1a755-5d8f-73e2-b6a9-cdfa8da9b004
You can choose which alerts you receive in your notification settings.
```

---

## 8. "Add to my phone calendar" (Settings and the first-task prompt)

A **one-way** subscription: tasks appear in Apple / Google / Outlook calendar on the phone and update by themselves (phones usually refresh every few hours).
Nothing a user does in the phone calendar changes Farmvest. Needs `task.view` (every member who can see tasks).

| Action | Call |
|---|---|
| Show state (Settings page) | `GET /calendar-feed` |
| Turn on | `POST /calendar-feed` |
| Make a new link (old one stops working) | `POST /calendar-feed` `{"regenerate": true}` |
| Add milestones too | `POST /calendar-feed` `{"include_milestones": true}` (keeps the same link) |
| Turn off | `DELETE /calendar-feed` |

**Settings screen:** show a switch "Add my tasks to my phone calendar". Off state:

```json
{
    "data": {
        "enabled": false,
        "include_milestones": false,
        "https_url": null,
        "webcal_url": null
    },
    "meta": [],
    "message": null
}
```

Turn on (`201`); show a button **Add to phone** that opens `webcal_url` (phones offer to subscribe), a **Copy link** button for `https_url` (for Google Calendar on
the web: "Add other calendars > From URL"), and a note that the link is private: anyone with it can read the user's tasks.

```json
{
    "data": {
        "enabled": true,
        "include_milestones": false,
        "https_url": "https://api.farmvest.example/api/v1/calendar-feed/3f9ac1d27b4e0658a91cd2e47f03b86a15d9e2c47a0b3f61.ics",
        "webcal_url": "webcal://api.farmvest.example/api/v1/calendar-feed/3f9ac1d27b4e0658a91cd2e47f03b86a15d9e2c47a0b3f61.ics"
    },
    "meta": [],
    "message": "Calendar link created."
}
```

(The real token is 48 characters; the example is shortened. `GET /calendar-feed` returns the same link again later.)

**New link** if the user thinks it leaked:

```json
{
    "regenerate": true
}
```

```json
{
    "data": {
        "enabled": true,
        "include_milestones": false,
        "https_url": "https://api.farmvest.example/api/v1/calendar-feed/3f9ac1d27b4e0658a91cd2e47f03b86a15d9e2c47a0b3f61.ics",
        "webcal_url": "webcal://api.farmvest.example/api/v1/calendar-feed/3f9ac1d27b4e0658a91cd2e47f03b86a15d9e2c47a0b3f61.ics"
    },
    "meta": [],
    "message": "Calendar link created."
}
```

**Turn off**: the link stops working at once (the phone calendar then shows an error or empties itself, which is expected):

```json
{
    "data": {
        "enabled": false,
        "include_milestones": false,
        "https_url": null,
        "webcal_url": null
    },
    "meta": [],
    "message": "Calendar link turned off."
}
```

**What the phone gets** (`GET {https_url}`, public, no login, `Content-Type: text/calendar`, rate limited, `404` for an unknown, switched-off or replaced link, or when
the member has lost access to the farm):

* That user's tasks from 30 days back to 180 days ahead, with the same visibility as `GET /calendar`, including coming repeats; cancelled tasks are left out,
  completed tasks are marked with a tick.
* All-day events when the task has no time, otherwise a 30-minute event at the farm-local time.
* Category, batch and a link back to the task; one alarm for each reminder of the task.
* With `include_milestones` also the cycle, breeding and health follow-up dates the user may see.

```json
{
    "message": "The requested resource was not found.",
    "code": "not_found",
    "request_id": "01a1a755-0000-7000-8000-000000000000"
}
```

An excerpt of the feed (lines are folded at 75 bytes, as the format requires):

```
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Farmvest//Farm tasks//EN
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:Green Acres tasks
X-WR-TIMEZONE:Africa/Lagos
REFRESH-INTERVAL;VALUE=DURATION:PT1H
X-PUBLISHED-TTL:PT1H
BEGIN:VTIMEZONE
TZID:Africa/Lagos
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0100
TZOFFSETTO:+0100
TZNAME:WAT
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:ms-cycle_start-01a1a755-424d-701d-a75a-07d5d83779ba-2026-10-01@api.farm
 vest.example
DTSTAMP:20261014T110000Z
SUMMARY:Layers — House 2 started
DTSTART;VALUE=DATE:20261001
DTEND;VALUE=DATE:20261002
CATEGORIES:Milestone
TRANSP:TRANSPARENT
END:VEVENT
BEGIN:VEVENT
UID:task-01a1a755-5d8f-73e2-b6a9-cdfa8da9b004@api.farmvest.example
DTSTAMP:20261014T110000Z
SUMMARY:Candle eggs
DTSTART;TZID=Africa/Lagos:20261020T140000
DTEND;TZID=Africa/Lagos:20261020T143000
CATEGORIES:Feeding / watering
DESCRIPTION:Category: Feeding / watering\nBatch: Layers — House 2\nOpen i
 n Farmvest: https://app.farmvest.example/tasks/01a1a755-5d8f-73e2-b6a9-cdf
 a8da9b004
URL:https://app.farmvest.example/tasks/01a1a755-5d8f-73e2-b6a9-cdfa8da9b004
STATUS:CONFIRMED
TRANSP:TRANSPARENT
X-BATCH:Layers — House 2
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Candle eggs
TRIGGER;RELATED=START:-PT7H
END:VALARM
END:VEVENT
END:VCALENDAR
```

---

## 9. Errors at a glance

| Status | `code` | Meaning |
|---|---|---|
| 401 | `unauthenticated` | Session expired: show login |
| 403 | `forbidden` | No `task.manage` / `task.complete` / `task.view` |
| 404 | `not_found` | Unknown task, schedule or calendar link (also tasks the role may not see) |
| 409 | `cycle_closed`, `project_not_active` | The batch is closed or the project is not active |
| 409 | `task_not_open` | Completed or cancelled tasks cannot be edited |
| 409 | `task_not_in_series` | "Edit this and all future" on a single task |
| 409 | `idempotency_conflict` | Same `idempotency_key` with different data |
| 422 | `validation_failed` | Field errors in `errors` (dotted keys such as `repeat.ends.date`) |
| 429 | `too_many_requests` | Honour `Retry-After` |
