# Frontend flow — Routines

A **routine** is a reusable set of steps ("what I do with every new batch", "the farm's monthly chores"). Using one creates ordinary **tasks** (and repeating schedules) for you; nothing is applied until the user confirms, and a routine never creates a record of something that happened (a task is work that *should* happen).

In the API a routine is a **work template** (`/work-templates`). Everything below was captured from the running application (Africa/Lagos, `today = 2026-10-14`); only the long arrays are shortened, and each shortening is said in the text. All paths have the prefix `/api/v1`, use the normal cookie session + CSRF, and never send `farm_id`. Reference: [`PHASE-12-WORK.md`](PHASE-12-WORK.md); sibling flow: [`FRONTEND-TASKS-FLOW.md`](FRONTEND-TASKS-FLOW.md). Permissions: `task.view` to read, `task.manage` to create / save / preview / apply / remove.

| Screen | Calls |
|---|---|
| Routines page (Mine / Ready-made) | `GET /work-templates?source=farm`, `GET /work-templates?source=platform` |
| New routine | `POST /work-templates` |
| Save a task as a routine | `POST /tasks/{task}/save-as-routine` |
| Use a routine: batch (or none) → start date → preview → confirm | `GET /work-templates?production_cycle_id=`, `POST …/preview`, `POST …/apply` |
| Routines on a batch | `GET /template-applications?production_cycle_id=`, `POST /template-applications/{id}/remove` |

## 1. What kind of routine is it (scope)

| `applies_to` | `scope` | Meaning |
|---|---|---|
| `production_cycle`, no filters | `any_batch` | Suits every batch ("Any batch"). |
| `production_cycle` + `cycle_kind` / `operation_type_id` / `species_id` / `crop_type_id` | `specific` | Only batches that match ("Chicken batches"). A filter that is not set is not checked. |
| `breeding_project` | `any_batch` / `specific` | The same idea for a breeding project (`breeding_workflow` can limit it). |
| `farm` | `general` | No batch: its tasks have no `production_cycle_id`. No filters, no record link, no "until the batch ends". |

Every row also carries `source` (`farm` = the user's own, `platform` = ready-made), `step_count`, `scope_label` (ready to display) and, when the list is asked for a batch, `suits_batch`.

## 2. The Routines page

**Mine** — the farm's own routines. Passing `production_cycle_id` adds `suits_batch` to each row (here the chicken batch *Broilers June*; the second routine is general, so it does not suit a batch).

```http
GET /work-templates?source=farm&production_cycle_id=01a1ac3c-7f21-7084-8b1d-e44228289412
```

`200`

```json
{
    "data": [
        {
            "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
            "source": "farm",
            "code": null,
            "name": "Broiler weekly care",
            "description": "What I do every week with a new flock.",
            "applies_to": "production_cycle",
            "cycle_kind": "livestock",
            "operation_type_id": null,
            "species_id": "01a1ac3b-cde2-729c-8779-9d17487ba446",
            "crop_type_id": null,
            "breeding_workflow": null,
            "version": 1,
            "is_active": true,
            "cloned_from_id": null,
            "application_id": null,
            "step_count": 3,
            "scope": "specific",
            "scope_label": "Chicken batches",
            "suits_batch": true,
            "items": [
                {
                    "id": "01a1ac3c-8398-7201-8e8a-cdacacbc314e",
                    "position": 1,
                    "title": "Check chicks and brooder heat",
                    "category": "growth_monitoring",
                    "instructions": null,
                    "anchor": "start_date",
                    "offset_days": 0,
                    "recurrence": "none",
                    "interval_value": 1,
                    "weekdays": null,
                    "until_offset_days": null,
                    "occurrence_limit": null,
                    "due_time": "07:00",
                    "reminder_offsets": null,
                    "reminders": [
                        "on_the_day"
                    ],
                    "repeat": null,
                    "assigned_role": null,
                    "linked_record_type": null,
                    "requires_evidence": false
                },
                {
                    "id": "01a1ac3c-839e-736b-b629-e3852c33325f",
                    "position": 2,
                    "title": "Weigh a sample of 30 birds",
                    "category": "growth_monitoring",
                    "instructions": null,
                    "anchor": "start_date",
                    "offset_days": 6,
                    "recurrence": "weekly",
                    "interval_value": 1,
                    "weekdays": [
                        5
                    ],
                    "until_offset_days": 40,
                    "occurrence_limit": null,
                    "due_time": null,
                    "reminder_offsets": null,
                    "reminders": [
                        "1_day",
                        "on_the_day"
                    ],
                    "repeat": {
                        "pattern": "weekly",
                        "every": 1,
                        "weekdays": [
                            5
                        ],
                        "ends": {
                            "type": "days",
                            "days": 35
                        }
                    },
                    "assigned_role": null,
                    "linked_record_type": null,
                    "requires_evidence": false
                },
                {
                    "id": "01a1ac3c-83a4-7197-b1a1-036cde7fc768",
                    "position": 3,
                    "title": "Clean drinkers",
                    "category": "cleaning_sanitation",
                    "instructions": null,
                    "anchor": "start_date",
                    "offset_days": 1,
                    "recurrence": "daily",
                    "interval_value": 1,
                    "weekdays": null,
                    "until_offset_days": null,
                    "occurrence_limit": null,
                    "due_time": null,
                    "reminder_offsets": null,
                    "reminders": null,
                    "repeat": {
                        "pattern": "daily",
                        "every": 1,
                        "weekdays": null,
                        "ends": {
                            "type": "batch_end"
                        }
                    },
                    "assigned_role": null,
                    "linked_record_type": null,
                    "requires_evidence": false
                }
            ]
        },
        {
            "id": "01a1ac3c-849e-7230-b5d1-557ea8d1a862",
            "source": "farm",
            "code": null,
            "name": "Monthly farm walk",
            "description": null,
            "applies_to": "farm",
            "cycle_kind": null,
            "operation_type_id": null,
            "species_id": null,
            "crop_type_id": null,
            "breeding_workflow": null,
            "version": 1,
            "is_active": true,
            "cloned_from_id": null,
            "application_id": null,
            "step_count": 1,
            "scope": "general",
            "scope_label": "General (no batch)",
            "suits_batch": false,
            "items": [
                {
                    "id": "01a1ac3c-84a4-708b-8e10-751c7c304c6c",
                    "position": 1,
                    "title": "Walk the fences and gates",
                    "category": "maintenance_repair",
                    "instructions": null,
                    "anchor": "start_date",
                    "offset_days": 0,
                    "recurrence": "monthly",
                    "interval_value": 1,
                    "weekdays": null,
                    "until_offset_days": null,
                    "occurrence_limit": null,
                    "due_time": null,
                    "reminder_offsets": null,
                    "reminders": [
                        "2_days"
                    ],
                    "repeat": {
                        "pattern": "monthly",
                        "every": 1,
                        "weekdays": null,
                        "ends": {
                            "type": "never"
                        }
                    },
                    "assigned_role": null,
                    "linked_record_type": null,
                    "requires_evidence": false
                }
            ]
        }
    ],
    "meta": [],
    "message": null
}
```

**Ready-made** — routines provided by the platform (read-only). The list is shortened to the first routine and its first step; the real response lists every routine and step. The routine below is a *breeding* routine, so for a batch `suits_batch` is `false`; use `?breeding_project_id=` to check a breeding project.


```http
GET /work-templates?source=platform&production_cycle_id=01a1ac3c-7f21-7084-8b1d-e44228289412
```

`200`

```json
{
    "data": [
        {
            "id": "01a1ac3c-0d03-71ca-bf2d-309d86b52d33",
            "source": "platform",
            "code": "chicken-incubation",
            "name": "Chicken incubation checks",
            "description": "Candling and hatch-day tasks relative to the incubation start and the expected hatch date.",
            "applies_to": "breeding_project",
            "cycle_kind": "livestock",
            "operation_type_id": null,
            "species_id": "01a1ac3b-cde2-729c-8779-9d17487ba446",
            "crop_type_id": null,
            "breeding_workflow": "incubation",
            "version": 1,
            "is_active": true,
            "cloned_from_id": null,
            "application_id": null,
            "step_count": 4,
            "scope": "specific",
            "scope_label": "Chicken breeding (incubation)",
            "suits_batch": false,
            "items": [
                {
                    "id": "01a1ac3c-0d07-7022-b073-a270f77eae2a",
                    "position": 1,
                    "title": "First candling",
                    "category": "breeding_reproduction",
                    "instructions": null,
                    "anchor": "breeding_start",
                    "offset_days": 7,
                    "recurrence": "none",
                    "interval_value": 1,
                    "weekdays": null,
                    "until_offset_days": null,
                    "occurrence_limit": null,
                    "due_time": null,
                    "reminder_offsets": null,
                    "reminders": null,
                    "repeat": null,
                    "assigned_role": null,
                    "linked_record_type": "breeding_check",
                    "requires_evidence": false
                }
            ]
        }
    ],
    "meta": [],
    "message": null
}
```

* Ready-made routines cannot be edited (`PATCH` → `403 platform_template_readonly`). The user's choices are **Use** (section 5) or **Customise**: `POST /work-templates/{id}/clone {"name": "…"}` creates an editable farm copy (version 1), then `PATCH /work-templates/{id}`.
* Editing a farm routine raises `version` by one. Routines already applied to a batch are **never** changed by a later edit.
* `GET /work-templates/{id}` returns one routine with all its steps.

## 3. New routine with steps

A step is written in the same language as the task form:

| Field | Meaning |
|---|---|
| `title`, `category`, `instructions`, `due_time` | As on a task. |
| `anchor` | What `offset_days` counts from. Default **`start_date`** = the date chosen when the routine is used; **day 1 is `offset_days` 0**. (Older anchors `cycle_start`, `cycle_expected_end`, `breeding_start`, `breeding_expected`, `breeding_expected_to` still work for batch / breeding routines.) |
| `offset_days` | 0 = the start date itself. |
| `repeat` | `null`/missing = one task. Otherwise `{pattern: daily|weekly|monthly, every, weekdays (weekly, 1 = Monday … 7 = Sunday), ends: {type, days, date}}`. `ends.type`: `days` (for N days from the step's first day), `date` (an absolute last day), `batch_end` (while the batch is open; batch routines only), `never`. |
| `reminders` | `on_the_day`, `1_day`, `2_days`, `7_days`. |
| `assigned_role`, `linked_record_type`, `requires_evidence` | As on a task (a general routine cannot link a record). |

The routine below is limited to chickens (`cycle_kind` + `species_id`) and has three steps: a one-off on day 1, a weekly weighing for 35 days, and a daily job that runs until the batch closes.

```http
POST /work-templates
Content-Type: application/json

{
    "name": "Broiler weekly care",
    "description": "What I do every week with a new flock.",
    "applies_to": "production_cycle",
    "cycle_kind": "livestock",
    "species_id": "01a1ac3b-cde2-729c-8779-9d17487ba446",
    "items": [
        {
            "title": "Check chicks and brooder heat",
            "category": "growth_monitoring",
            "anchor": "start_date",
            "offset_days": 0,
            "due_time": "07:00",
            "reminders": [
                "on_the_day"
            ]
        },
        {
            "title": "Weigh a sample of 30 birds",
            "category": "growth_monitoring",
            "anchor": "start_date",
            "offset_days": 6,
            "reminders": [
                "1_day",
                "on_the_day"
            ],
            "repeat": {
                "pattern": "weekly",
                "every": 1,
                "weekdays": [
                    5
                ],
                "ends": {
                    "type": "days",
                    "days": 35
                }
            }
        },
        {
            "title": "Clean drinkers",
            "category": "cleaning_sanitation",
            "anchor": "start_date",
            "offset_days": 1,
            "repeat": {
                "pattern": "daily",
                "every": 1,
                "ends": {
                    "type": "batch_end"
                }
            }
        }
    ]
}
```

`201`

```json
{
    "data": {
        "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
        "source": "farm",
        "code": null,
        "name": "Broiler weekly care",
        "description": "What I do every week with a new flock.",
        "applies_to": "production_cycle",
        "cycle_kind": "livestock",
        "operation_type_id": null,
        "species_id": "01a1ac3b-cde2-729c-8779-9d17487ba446",
        "crop_type_id": null,
        "breeding_workflow": null,
        "version": 1,
        "is_active": true,
        "cloned_from_id": null,
        "application_id": null,
        "step_count": 3,
        "scope": "specific",
        "scope_label": "Chicken batches",
        "items": [
            {
                "id": "01a1ac3c-8398-7201-8e8a-cdacacbc314e",
                "position": 1,
                "title": "Check chicks and brooder heat",
                "category": "growth_monitoring",
                "instructions": null,
                "anchor": "start_date",
                "offset_days": 0,
                "recurrence": "none",
                "interval_value": 1,
                "weekdays": null,
                "until_offset_days": null,
                "occurrence_limit": null,
                "due_time": "07:00",
                "reminder_offsets": null,
                "reminders": [
                    "on_the_day"
                ],
                "repeat": null,
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false
            },
            {
                "id": "01a1ac3c-839e-736b-b629-e3852c33325f",
                "position": 2,
                "title": "Weigh a sample of 30 birds",
                "category": "growth_monitoring",
                "instructions": null,
                "anchor": "start_date",
                "offset_days": 6,
                "recurrence": "weekly",
                "interval_value": 1,
                "weekdays": [
                    5
                ],
                "until_offset_days": 40,
                "occurrence_limit": null,
                "due_time": null,
                "reminder_offsets": null,
                "reminders": [
                    "1_day",
                    "on_the_day"
                ],
                "repeat": {
                    "pattern": "weekly",
                    "every": 1,
                    "weekdays": [
                        5
                    ],
                    "ends": {
                        "type": "days",
                        "days": 35
                    }
                },
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false
            },
            {
                "id": "01a1ac3c-83a4-7197-b1a1-036cde7fc768",
                "position": 3,
                "title": "Clean drinkers",
                "category": "cleaning_sanitation",
                "instructions": null,
                "anchor": "start_date",
                "offset_days": 1,
                "recurrence": "daily",
                "interval_value": 1,
                "weekdays": null,
                "until_offset_days": null,
                "occurrence_limit": null,
                "due_time": null,
                "reminder_offsets": null,
                "reminders": null,
                "repeat": {
                    "pattern": "daily",
                    "every": 1,
                    "weekdays": null,
                    "ends": {
                        "type": "batch_end"
                    }
                },
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false
            }
        ]
    },
    "meta": [],
    "message": "Template created."
}
```

A **general** routine (`applies_to: farm`) — a monthly chore for the whole farm. The anchor is `start_date` and does not need to be sent:


```http
POST /work-templates
Content-Type: application/json

{
    "name": "Monthly farm walk",
    "applies_to": "farm",
    "items": [
        {
            "title": "Walk the fences and gates",
            "category": "maintenance_repair",
            "repeat": {
                "pattern": "monthly",
                "every": 1,
                "ends": {
                    "type": "never"
                }
            },
            "reminders": [
                "2_days"
            ]
        }
    ]
}
```

`201`

```json
{
    "data": {
        "id": "01a1ac3c-849e-7230-b5d1-557ea8d1a862",
        "source": "farm",
        "code": null,
        "name": "Monthly farm walk",
        "description": null,
        "applies_to": "farm",
        "cycle_kind": null,
        "operation_type_id": null,
        "species_id": null,
        "crop_type_id": null,
        "breeding_workflow": null,
        "version": 1,
        "is_active": true,
        "cloned_from_id": null,
        "application_id": null,
        "step_count": 1,
        "scope": "general",
        "scope_label": "General (no batch)",
        "items": [
            {
                "id": "01a1ac3c-84a4-708b-8e10-751c7c304c6c",
                "position": 1,
                "title": "Walk the fences and gates",
                "category": "maintenance_repair",
                "instructions": null,
                "anchor": "start_date",
                "offset_days": 0,
                "recurrence": "monthly",
                "interval_value": 1,
                "weekdays": null,
                "until_offset_days": null,
                "occurrence_limit": null,
                "due_time": null,
                "reminder_offsets": null,
                "reminders": [
                    "2_days"
                ],
                "repeat": {
                    "pattern": "monthly",
                    "every": 1,
                    "weekdays": null,
                    "ends": {
                        "type": "never"
                    }
                },
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false
            }
        ]
    },
    "meta": [],
    "message": "Template created."
}
```

Mistakes are normal validation errors keyed by field, for example a general routine cannot end with a batch:


```http
POST /work-templates
Content-Type: application/json

{
    "name": "Bad",
    "applies_to": "farm",
    "items": [
        {
            "title": "Daily",
            "category": "other",
            "repeat": {
                "pattern": "daily",
                "ends": {
                    "type": "batch_end"
                }
            }
        }
    ]
}
```

`422`

```json
{
    "message": "A general routine has no batch to end with.",
    "code": "validation_failed",
    "request_id": "01a1ac3c-9935-7078-9f43-7106271c1c23",
    "errors": {
        "items.0.repeat.ends.type": [
            "A general routine has no batch to end with."
        ]
    }
}
```

## 4. Save as routine

On a task's menu: **Save as routine**. If the task repeats, the whole series is saved (its repeat, end and reminders), otherwise just the one task. The routine has one step, anchored on `start_date` at offset 0. Nothing is created as a task.

`scope`: `any_batch` (default for a task that belongs to a batch), `same_kind` (only batches of the same species / crop) or `general` (default for a task with no batch; refused with 422 when the task ends with the batch or links a record, because a general routine has no batch).

Here the task is one of the occurrences of "Check water lines" (every Tuesday and Friday for 28 days, reminder one day before). The response shows the repeat, the 28 days and the reminder carried over, and the chicken filter added by `same_kind`.

```http
POST /tasks/01a1ac3c-86ca-715b-bec9-4324f1a11778/save-as-routine
Content-Type: application/json

{
    "name": "Water line checks",
    "scope": "same_kind"
}
```

`201`

```json
{
    "data": {
        "id": "01a1ac3c-87af-73cb-a5f5-dd3893f9964e",
        "source": "farm",
        "code": null,
        "name": "Water line checks",
        "description": null,
        "applies_to": "production_cycle",
        "cycle_kind": "livestock",
        "operation_type_id": null,
        "species_id": "01a1ac3b-cde2-729c-8779-9d17487ba446",
        "crop_type_id": null,
        "breeding_workflow": null,
        "version": 1,
        "is_active": true,
        "cloned_from_id": null,
        "application_id": null,
        "step_count": 1,
        "scope": "specific",
        "scope_label": "Chicken batches",
        "items": [
            {
                "id": "01a1ac3c-87b5-7222-8ab2-70b08aaa5ef2",
                "position": 1,
                "title": "Check water lines",
                "category": "maintenance_repair",
                "instructions": null,
                "anchor": "start_date",
                "offset_days": 0,
                "recurrence": "weekly",
                "interval_value": 1,
                "weekdays": [
                    2,
                    5
                ],
                "until_offset_days": 27,
                "occurrence_limit": null,
                "due_time": null,
                "reminder_offsets": null,
                "reminders": [
                    "1_day"
                ],
                "repeat": {
                    "pattern": "weekly",
                    "every": 1,
                    "weekdays": [
                        2,
                        5
                    ],
                    "ends": {
                        "type": "days",
                        "days": 28
                    }
                },
                "assigned_role": null,
                "linked_record_type": null,
                "requires_evidence": false
            }
        ]
    },
    "meta": [],
    "message": "Routine saved."
}
```

## 5. Use a routine

1. **Pick the routine** (Routines page, or a batch's "Add routine" button). From a batch, list with `?production_cycle_id=` and offer the rows with `suits_batch: true`.
2. **Choose the batch, or none.** A routine with `scope: general` is used with no batch; every other routine needs one (a batch id; a breeding project id for breeding routines).
3. **Choose the start date.** Day 1 of the routine. Default when none is sent: the batch's start date for a batch routine, today for a general one. A past date is allowed; occurrences already in the past are simply not created (`skipped_past`).
4. **Preview** — nothing is saved.
5. **Confirm** — `apply`.

### Preview

`target` is the batch id (leave it out for a general routine). Only the first 30 days are turned into tasks (the same window applying uses); `coming_repeats` tells the user that a repeat goes on after that and when the next one is (`remaining_after_window` is `null` when it has no end). The request and the real response below; `jobs` is shortened to the first five of 28.

```http
POST /work-templates/01a1ac3c-838e-7193-8fcd-921886e0d150/preview
Content-Type: application/json

{
    "target": "01a1ac3c-7f21-7084-8b1d-e44228289412",
    "start_date": "2026-10-20"
}
```

`200`

```json
{
    "data": {
        "template": {
            "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
            "name": "Broiler weekly care",
            "version": 1
        },
        "target": {
            "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
            "breeding_project_id": null,
            "general": false
        },
        "start_date": "2026-10-20",
        "window": {
            "from": "2026-10-14",
            "to": "2026-11-13"
        },
        "counts": {
            "steps": 3,
            "schedules": 3,
            "jobs_in_window": 28,
            "skipped_past": 0,
            "skipped_no_anchor": 0,
            "coming_repeats": 2
        },
        "jobs": [
            {
                "step": 1,
                "title": "Check chicks and brooder heat",
                "category": "growth_monitoring",
                "due_date": "2026-10-20",
                "due_time": "07:00",
                "reminders": [
                    "on_the_day"
                ],
                "repeats": false
            },
            {
                "step": 3,
                "title": "Clean drinkers",
                "category": "cleaning_sanitation",
                "due_date": "2026-10-21",
                "due_time": null,
                "reminders": null,
                "repeats": true
            },
            {
                "step": 3,
                "title": "Clean drinkers",
                "category": "cleaning_sanitation",
                "due_date": "2026-10-22",
                "due_time": null,
                "reminders": null,
                "repeats": true
            },
            {
                "step": 3,
                "title": "Clean drinkers",
                "category": "cleaning_sanitation",
                "due_date": "2026-10-23",
                "due_time": null,
                "reminders": null,
                "repeats": true
            },
            {
                "step": 3,
                "title": "Clean drinkers",
                "category": "cleaning_sanitation",
                "due_date": "2026-10-24",
                "due_time": null,
                "reminders": null,
                "repeats": true
            }
        ],
        "coming_repeats": [
            {
                "step": 2,
                "title": "Weigh a sample of 30 birds",
                "repeat": {
                    "pattern": "weekly",
                    "every": 1,
                    "weekdays": [
                        5
                    ]
                },
                "ends": {
                    "type": "date",
                    "date": "2026-11-29"
                },
                "next_date": "2026-11-20",
                "remaining_after_window": 2
            },
            {
                "step": 3,
                "title": "Clean drinkers",
                "repeat": {
                    "pattern": "daily",
                    "every": 1,
                    "weekdays": null
                },
                "ends": {
                    "type": "batch_end"
                },
                "next_date": "2026-11-14",
                "remaining_after_window": null
            }
        ],
        "times_already_applied": 0
    },
    "meta": [],
    "message": null
}
```

Steps on the older anchors (`cycle_start`, …) ignore `start_date`. A general routine previews the same way, without a target:


```http
POST /work-templates/01a1ac3c-849e-7230-b5d1-557ea8d1a862/preview
Content-Type: application/json

{
    "start_date": "2026-10-16"
}
```

`200`

```json
{
    "data": {
        "template": {
            "id": "01a1ac3c-849e-7230-b5d1-557ea8d1a862",
            "name": "Monthly farm walk",
            "version": 1
        },
        "target": {
            "production_cycle_id": null,
            "breeding_project_id": null,
            "general": true
        },
        "start_date": "2026-10-16",
        "window": {
            "from": "2026-10-14",
            "to": "2026-11-13"
        },
        "counts": {
            "steps": 1,
            "schedules": 1,
            "jobs_in_window": 1,
            "skipped_past": 0,
            "skipped_no_anchor": 0,
            "coming_repeats": 1
        },
        "jobs": [
            {
                "step": 1,
                "title": "Walk the fences and gates",
                "category": "maintenance_repair",
                "due_date": "2026-10-16",
                "due_time": null,
                "reminders": [
                    "2_days"
                ],
                "repeats": true
            }
        ],
        "coming_repeats": [
            {
                "step": 1,
                "title": "Walk the fences and gates",
                "repeat": {
                    "pattern": "monthly",
                    "every": 1,
                    "weekdays": null
                },
                "ends": {
                    "type": "never"
                },
                "next_date": "2026-11-16",
                "remaining_after_window": null
            }
        ],
        "times_already_applied": 0
    },
    "meta": [],
    "message": null
}
```

### Confirm

Same body plus the `idempotency_key` (generate one per click; a retry with the same key returns the same application). `data.id` is the **application**: keep it to show and remove the routine later.


```http
POST /work-templates/01a1ac3c-838e-7193-8fcd-921886e0d150/apply
Content-Type: application/json

{
    "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
    "start_date": "2026-10-20",
    "idempotency_key": "capture-apply-0001"
}
```

`201`

```json
{
    "data": {
        "id": "01a1ac3c-912b-73aa-86d6-353e661157d5",
        "work_template_id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
        "template_version": 1,
        "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
        "breeding_project_id": null,
        "status": "active",
        "start_date": "2026-10-20",
        "schedules_created": 3,
        "tasks_created": 28,
        "skipped_past": 0,
        "skipped_no_anchor": 0,
        "removed_at": null,
        "tasks_cancelled": 0,
        "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
        "applied_at": "2026-10-14T11:00:00.000000Z",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Template applied."
}
```

A general routine is applied with no batch; its tasks have no batch:


```http
POST /work-templates/01a1ac3c-849e-7230-b5d1-557ea8d1a862/apply
Content-Type: application/json

{
    "start_date": "2026-10-16",
    "idempotency_key": "capture-apply-0005"
}
```

`201`

```json
{
    "data": {
        "id": "01a1ac3c-9876-70a6-887a-476c33ccfd9d",
        "work_template_id": "01a1ac3c-849e-7230-b5d1-557ea8d1a862",
        "template_version": 1,
        "production_cycle_id": null,
        "breeding_project_id": null,
        "status": "active",
        "start_date": "2026-10-16",
        "schedules_created": 1,
        "tasks_created": 1,
        "skipped_past": 0,
        "skipped_no_anchor": 0,
        "removed_at": null,
        "tasks_cancelled": 0,
        "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
        "applied_at": "2026-10-14T11:00:00.000000Z",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Template applied."
}
```

## 6. Applying the same routine again

The same routine may be applied to the same batch again (for example a second round). Without the user's confirmation the API answers **409 `template_already_applied`** and tells you what happened before, so you can ask "This routine was already used on this batch on … (28 tasks). Use it again?":

```http
POST /work-templates/01a1ac3c-838e-7193-8fcd-921886e0d150/apply
Content-Type: application/json

{
    "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
    "start_date": "2026-10-27",
    "idempotency_key": "capture-apply-0002"
}
```

`409`

```json
{
    "message": "This routine was already applied to this target. Send confirm_reapply=true to apply it again.",
    "code": "template_already_applied",
    "request_id": "01a1ac3c-936e-702d-bd66-c7c9c8e4b4e4",
    "details": {
        "times_applied": 1,
        "last_applied_at": "2026-10-14T11:00:00.000000Z",
        "applications": [
            {
                "id": "01a1ac3c-912b-73aa-86d6-353e661157d5",
                "applied_at": "2026-10-14T11:00:00.000000Z",
                "start_date": "2026-10-20",
                "tasks_created": 28
            }
        ]
    }
}
```

If the user agrees, send the same request with `confirm_reapply: true` and a new `idempotency_key`. A second application is created next to the first (the first is untouched):


```http
POST /work-templates/01a1ac3c-838e-7193-8fcd-921886e0d150/apply
Content-Type: application/json

{
    "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
    "start_date": "2026-10-27",
    "confirm_reapply": true,
    "idempotency_key": "capture-apply-0003"
}
```

`201`

```json
{
    "data": {
        "id": "01a1ac3c-947d-7209-bfce-60d73b39093b",
        "work_template_id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
        "template_version": 1,
        "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
        "breeding_project_id": null,
        "status": "active",
        "start_date": "2026-10-27",
        "schedules_created": 3,
        "tasks_created": 20,
        "skipped_past": 0,
        "skipped_no_anchor": 0,
        "removed_at": null,
        "tasks_cancelled": 0,
        "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
        "applied_at": "2026-10-14T11:00:00.000000Z",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Template applied."
}
```

`preview` also returns `times_already_applied`, so you can show the warning before the confirm step.

## 7. Routines on a batch, and removing one

`GET /template-applications?production_cycle_id=…` lists the routines applied to a batch, newest first, each with the routine's name, the `start_date`, `applied_at` and live `task_counts`. Other filters: `breeding_project_id`, `target=general` (routines applied with no batch), `work_template_id`, `status=active|removed|all` (default `active`). Shown here: two applications of the same routine (the two apply calls above).

```http
GET /template-applications?production_cycle_id=01a1ac3c-7f21-7084-8b1d-e44228289412
```

`200`

```json
{
    "data": [
        {
            "id": "01a1ac3c-947d-7209-bfce-60d73b39093b",
            "work_template_id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
            "template_version": 1,
            "template": {
                "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
                "name": "Broiler weekly care",
                "source": "farm"
            },
            "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
            "breeding_project_id": null,
            "status": "active",
            "start_date": "2026-10-27",
            "schedules_created": 3,
            "tasks_created": 20,
            "skipped_past": 0,
            "skipped_no_anchor": 0,
            "task_counts": {
                "open": 20,
                "completed": 0,
                "cancelled": 0
            },
            "removed_at": null,
            "tasks_cancelled": 0,
            "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
            "applied_at": "2026-10-14T11:00:00.000000Z",
            "created_at": "2026-10-14T11:00:00.000000Z"
        },
        {
            "id": "01a1ac3c-912b-73aa-86d6-353e661157d5",
            "work_template_id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
            "template_version": 1,
            "template": {
                "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
                "name": "Broiler weekly care",
                "source": "farm"
            },
            "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
            "breeding_project_id": null,
            "status": "active",
            "start_date": "2026-10-20",
            "schedules_created": 3,
            "tasks_created": 28,
            "skipped_past": 0,
            "skipped_no_anchor": 0,
            "task_counts": {
                "open": 28,
                "completed": 0,
                "cancelled": 0
            },
            "removed_at": null,
            "tasks_cancelled": 0,
            "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
            "applied_at": "2026-10-14T11:00:00.000000Z",
            "created_at": "2026-10-14T11:00:00.000000Z"
        }
    ],
    "meta": [],
    "message": null
}
```

**Remove** from the batch. Every schedule the routine created is ended and its **open tasks due today or later** are cancelled (`cancel_reason` "Routine removed"). Tasks already completed are never touched, and neither are open tasks that are already overdue (the user can still finish them). Nothing is deleted. Removing again returns the same result.


```http
POST /template-applications/01a1ac3c-912b-73aa-86d6-353e661157d5/remove
```

`200`

```json
{
    "data": {
        "id": "01a1ac3c-912b-73aa-86d6-353e661157d5",
        "work_template_id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
        "template_version": 1,
        "template": {
            "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
            "name": "Broiler weekly care",
            "source": "farm"
        },
        "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
        "breeding_project_id": null,
        "status": "removed",
        "start_date": "2026-10-20",
        "schedules_created": 3,
        "tasks_created": 28,
        "skipped_past": 0,
        "skipped_no_anchor": 0,
        "task_counts": {
            "open": 0,
            "completed": 0,
            "cancelled": 28
        },
        "removed_at": "2026-10-14T11:00:00.000000Z",
        "tasks_cancelled": 28,
        "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
        "applied_at": "2026-10-14T11:00:00.000000Z",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Routine removed."
}
```

Removing a second time is harmless:


```http
POST /template-applications/01a1ac3c-912b-73aa-86d6-353e661157d5/remove
```

`200`

```json
{
    "data": {
        "id": "01a1ac3c-912b-73aa-86d6-353e661157d5",
        "work_template_id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
        "template_version": 1,
        "template": {
            "id": "01a1ac3c-838e-7193-8fcd-921886e0d150",
            "name": "Broiler weekly care",
            "source": "farm"
        },
        "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
        "breeding_project_id": null,
        "status": "removed",
        "start_date": "2026-10-20",
        "schedules_created": 3,
        "tasks_created": 28,
        "skipped_past": 0,
        "skipped_no_anchor": 0,
        "task_counts": {
            "open": 0,
            "completed": 0,
            "cancelled": 28
        },
        "removed_at": "2026-10-14T11:00:00.000000Z",
        "tasks_cancelled": 28,
        "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
        "applied_at": "2026-10-14T11:00:00.000000Z",
        "created_at": "2026-10-14T11:00:00.000000Z"
    },
    "meta": [],
    "message": "Routine removed."
}
```

General routines are listed with `?target=general`:


```http
GET /template-applications?target=general
```

`200`

```json
{
    "data": [
        {
            "id": "01a1ac3c-9876-70a6-887a-476c33ccfd9d",
            "work_template_id": "01a1ac3c-849e-7230-b5d1-557ea8d1a862",
            "template_version": 1,
            "template": {
                "id": "01a1ac3c-849e-7230-b5d1-557ea8d1a862",
                "name": "Monthly farm walk",
                "source": "farm"
            },
            "production_cycle_id": null,
            "breeding_project_id": null,
            "status": "active",
            "start_date": "2026-10-16",
            "schedules_created": 1,
            "tasks_created": 1,
            "skipped_past": 0,
            "skipped_no_anchor": 0,
            "task_counts": {
                "open": 1,
                "completed": 0,
                "cancelled": 0
            },
            "removed_at": null,
            "tasks_cancelled": 0,
            "created_by": "01a1ac3c-7d9a-73a4-961e-4cc559ed1203",
            "applied_at": "2026-10-14T11:00:00.000000Z",
            "created_at": "2026-10-14T11:00:00.000000Z"
        }
    ],
    "meta": [],
    "message": null
}
```

## 8. Errors

| Status / `code` | When | What to show |
|---|---|---|
| `422 validation_failed` | A step or field is invalid; the routine does not fit the batch; a general routine is given a batch (or a batch routine none). `errors` is keyed by field. | Field messages. |
| `409 template_already_applied` | Already applied to this target and `confirm_reapply` was not `true`. `details.times_applied`, `details.last_applied_at`, `details.applications[]`. | The confirmation dialog (section 6). |
| `409 cycle_closed`, `project_not_active` | The batch is closed / the breeding project is not active. | Ask the user to reopen it. |
| `409 template_inactive` | The routine was deactivated. | Hide it. |
| `409 idempotency_conflict` | The key was used for a different request. | Generate a new key. |
| `403 platform_template_readonly` | Editing a ready-made routine. | Offer **Customise** (clone). |
| `403 forbidden` | Missing `task.manage` / `task.view`. | Hide the action. |
| `404 not_found` | Unknown routine, task, batch or application, or one that belongs to another farm. | — |

The routine does not fit the batch (a goat batch for a chicken routine):

```http
POST /work-templates/01a1ac3c-838e-7193-8fcd-921886e0d150/apply
Content-Type: application/json

{
    "production_cycle_id": "01a1ac3c-809d-7363-8c74-ca436065bddb",
    "idempotency_key": "capture-apply-0004"
}
```

`422`

```json
{
    "message": "This template does not fit the selected production cycle.",
    "code": "validation_failed",
    "request_id": "01a1ac3c-9677-712a-9e24-f7e502743865",
    "errors": {
        "production_cycle_id": [
            "This template does not fit the selected production cycle."
        ]
    }
}
```

A general routine used with a batch:


```http
POST /work-templates/01a1ac3c-849e-7230-b5d1-557ea8d1a862/apply
Content-Type: application/json

{
    "production_cycle_id": "01a1ac3c-7f21-7084-8b1d-e44228289412",
    "idempotency_key": "capture-apply-0006"
}
```

`422`

```json
{
    "message": "This is a general routine; apply it without a batch.",
    "code": "validation_failed",
    "request_id": "01a1ac3c-98c4-73c9-ba56-062b464536f4",
    "errors": {
        "production_cycle_id": [
            "This is a general routine; apply it without a batch."
        ]
    }
}
```

Notes

* A preview or apply for a batch routine without a batch is `422` on `production_cycle_id` (`breeding_project_id` for breeding routines).
* Templates and routines are configuration, not veterinary or agronomic advice.
