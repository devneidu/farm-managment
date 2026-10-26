# Breeding flow — frontend contract

Two forms cover every kind of breeding: **Incubation** (eggs go in, chicks hatch) and **Pregnancy** (females are bred, young are born). The farmer picks a batch, the app shows the right form, and the rest is automatic: dates are worked out, progress shows by itself, and when the hatch or birth is recorded the new animals appear in the farm's head count.

All paths are under `/api/v1`. Auth, CSRF and the optional `X-Farm-Id` header are as in [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md). The examples are real responses captured from the running code (Africa/Lagos farm); only long unchanged parts were trimmed where a note says so. Reference: [PHASE-11-BREEDING.md](PHASE-11-BREEDING.md). Permissions: `breeding.view` to read, `breeding.create` to start, check, record and cancel, `breeding.reverse` to undo (managers and owners).

## 1. Pick the form from the batch

Every batch in `GET /production-cycles` and `GET /production-cycles/{id}` carries `livestock.breeding_workflow`:

| `breeding_workflow` | Show |
|---|---|
| `incubation` | the **Incubation** form (chicken, turkey, duck, ...) |
| `pregnancy` | the **Pregnancy** form (cattle, goat, sheep, pig, ...) |
| `null` | no breeding button for this batch (fish, for example) |

One species has one workflow; never decide it from the species name. Send the same value as `workflow` when you start the project.

```json
{
    "data": [
        {
            "id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
            "name": "Layer house 1",
            "species": "chicken",
            "breeding_workflow": "incubation",
            "current_population": 120
        },
        {
            "id": "01a16a3d-4f21-721e-87ed-18a252044547",
            "name": "Dairy herd",
            "species": "cattle",
            "breeding_workflow": "pregnancy",
            "current_population": 14
        },
        {
            "id": "01a16a3d-51ea-7060-ae90-d18a925bc094",
            "name": "Bulls",
            "species": "cattle",
            "breeding_workflow": "pregnancy",
            "current_population": 2
        },
        {
            "id": "01a16a3d-5474-7125-8838-537e126e5658",
            "name": "Pond 1",
            "species": "fish",
            "breeding_workflow": null,
            "current_population": 500
        }
    ],
    "_note": "GET /production-cycles - only the fields this guide uses are shown"
}
```

Starting a project on a batch whose `breeding_workflow` is `null`, or with the other workflow, is refused:

`422` "validation_failed" - This species does not support the incubation breeding workflow.

## 2. Start a project (create form)

The batch the farmer picked is the **mother batch** — the hens that laid the eggs, the cows that were bred. It is sent as `production_cycle_id` and is also recorded as the dam; there is nothing else to choose for the mother.

### 2.1 Fields

| Field | Incubation | Pregnancy |
|---|---|---|
| `production_cycle_id` | required: the mother batch | required: the mother batch |
| `workflow` | `incubation` | `pregnancy` |
| `start_date` | required: the day the eggs were set (not in the future, not before the batch started) | required: the day of mating or insemination |
| `eggs_set` | **required** | not allowed |
| `females_bred` | not allowed | optional, at most the batch's current head count |
| `expected_offspring` | optional estimate | optional estimate |
| `title` | optional name; left blank it becomes "Chicken hatch – 2026-10-01" | optional; left blank "Cattle breeding – 2026-10-05" |
| `parents` | optional, see below | optional, see below |
| `consume_egg_stock`, `egg_storage_location_id` | optional: take the eggs out of the egg stock | not allowed |
| `notes`, `expected_date` / `expected_from` + `expected_to` | optional (own due date instead of the calculated one) | optional |
| `idempotency_key` | required; generate once when the form opens | required |

**Father (sire) is optional.** Leave `parents` out, or send `{"role": "sire", "production_cycle_id": "<any batch of the same species>", "head_count": 1}`. The sire can be another batch (a bull pen) or the mother batch itself (a flock with cockerels). A parent `head_count` is optional and may not exceed what that batch holds now. A `dam` entry is not needed; if you send one it must be the mother batch.

### 2.2 Incubation

`POST /breeding-projects`

Request:

```json
{
    "production_cycle_id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
    "workflow": "incubation",
    "start_date": "2026-10-01",
    "eggs_set": 60,
    "expected_offspring": 50,
    "idempotency_key": "start-hatch-001"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-5ae9-738b-8b42-4925927f6222",
        "reference": "BRD-2026-00001",
        "title": "Chicken hatch – 2026-10-01",
        "production_cycle_id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
        "default_destination": "new_batch",
        "workflow": "incubation",
        "status": "active",
        "start_date": "2026-10-01",
        "eggs_set": 60,
        "females_bred": null,
        "expected_offspring": 50,
        "expectation": {
            "type": "exact",
            "source": "reference",
            "date": "2026-10-22",
            "from": null,
            "to": null,
            "no_expectation_reason": null
        },
        "biological_reference": {
            "workflow": "incubation",
            "capability": "supports_incubation",
            "species_id": "01a16a3a-c87c-72c0-b34b-dc9347d3dac5",
            "species_code": "chicken",
            "kind": "exact",
            "days": 21,
            "days_min": null,
            "days_max": null,
            "approximate": false,
            "note": null,
            "automatic_expectation": true,
            "no_automatic_reason": null,
            "captured_at": "2026-10-23T19:29:28.230898Z"
        },
        "egg_stock": null,
        "parents": [
            {
                "id": "01a16a3d-5b20-700a-8e7b-efe3372aae8e",
                "role": "dam",
                "production_cycle_id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
                "head_count": null
            }
        ],
        "checks": [],
        "outcomes": [],
        "result": null,
        "notes": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "created_by": "01a16a3d-469d-726b-a767-762a8a2b5842",
        "created_at": "2026-10-23T19:29:28.000000Z",
        "updated_at": "2026-10-23T19:29:28.000000Z"
    },
    "meta": [],
    "message": "Breeding project started."
}
```


### 2.3 Pregnancy, with a sire

`POST /breeding-projects`

Request:

```json
{
    "production_cycle_id": "01a16a3d-4f21-721e-87ed-18a252044547",
    "workflow": "pregnancy",
    "start_date": "2026-10-05",
    "females_bred": 4,
    "expected_offspring": 4,
    "title": "October calving",
    "parents": [
        {
            "role": "sire",
            "production_cycle_id": "01a16a3d-51ea-7060-ae90-d18a925bc094",
            "head_count": 1
        }
    ],
    "idempotency_key": "start-calving-001"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-5d96-702b-b820-3a8cfe96ef9d",
        "reference": "BRD-2026-00002",
        "title": "October calving",
        "production_cycle_id": "01a16a3d-4f21-721e-87ed-18a252044547",
        "default_destination": "mother_batch",
        "workflow": "pregnancy",
        "status": "active",
        "start_date": "2026-10-05",
        "eggs_set": null,
        "females_bred": 4,
        "expected_offspring": 4,
        "expectation": {
            "type": "exact",
            "source": "reference",
            "date": "2027-07-15",
            "from": null,
            "to": null,
            "no_expectation_reason": null
        },
        "biological_reference": {
            "workflow": "pregnancy",
            "capability": "supports_pregnancy",
            "species_id": "01a16a3a-c8aa-734d-866c-a65bfec59a87",
            "species_code": "cattle",
            "kind": "exact",
            "days": 283,
            "days_min": 280,
            "days_max": 285,
            "approximate": true,
            "note": null,
            "automatic_expectation": true,
            "no_automatic_reason": null,
            "captured_at": "2026-10-23T19:29:28.902328Z"
        },
        "egg_stock": null,
        "parents": [
            {
                "id": "01a16a3d-5dc3-735e-8b3f-0197d01cb86b",
                "role": "dam",
                "production_cycle_id": "01a16a3d-4f21-721e-87ed-18a252044547",
                "head_count": null
            },
            {
                "id": "01a16a3d-5da9-70b7-892c-41bc4db8ce47",
                "role": "sire",
                "production_cycle_id": "01a16a3d-51ea-7060-ae90-d18a925bc094",
                "head_count": 1
            }
        ],
        "checks": [],
        "outcomes": [],
        "result": null,
        "notes": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "created_by": "01a16a3d-469d-726b-a767-762a8a2b5842",
        "created_at": "2026-10-23T19:29:28.000000Z",
        "updated_at": "2026-10-23T19:29:28.000000Z"
    },
    "meta": [],
    "message": "Breeding project started."
}
```


### 2.4 Automatic progress and due dates

There is no status for the farmer to set. `expectation` holds the calculated due date (`exact`) or window (`window`, never a midpoint), or `none` when the species has no reliable number (the farmer can then type their own). `status` goes `active` → `completed` by itself when the outcome is recorded, or `cancelled` if the project is abandoned. `GET /breeding-projects/{id}/milestones` returns the timeline to draw (started, expected outcome with `pending` / `overdue` / `done`, each check, the outcome):

`GET /breeding-projects/01a16a3d-5ae9-738b-8b42-4925927f6222/milestones`

Response `200`:

```json
{
    "data": [
        {
            "code": "started",
            "date": "2026-10-01",
            "from": null,
            "to": null,
            "status": "done"
        },
        {
            "code": "expected_outcome",
            "date": "2026-10-22",
            "from": null,
            "to": null,
            "status": "overdue"
        }
    ],
    "meta": [],
    "message": null
}
```


## 3. Checks

Record a pregnancy check or an egg candling whenever one happens. Checks only add information; they never change the head count. `fertile_count` is for incubation only and cannot exceed `eggs_set`.

`POST /breeding-projects/01a16a3d-5ae9-738b-8b42-4925927f6222/checks`

Request:

```json
{
    "checked_on": "2026-10-08",
    "result": "positive",
    "fertile_count": 52,
    "notes": "Candled on day 7"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-5ae9-738b-8b42-4925927f6222",
        "reference": "BRD-2026-00001",
        "title": "Chicken hatch – 2026-10-01",
        "production_cycle_id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
        "default_destination": "new_batch",
        "workflow": "incubation",
        "status": "active",
        "start_date": "2026-10-01",
        "eggs_set": 60,
        "females_bred": null,
        "expected_offspring": 50,
        "expectation": {
            "type": "exact",
            "source": "reference",
            "date": "2026-10-22",
            "from": null,
            "to": null,
            "no_expectation_reason": null
        },
        "biological_reference": {
            "days": 21,
            "kind": "exact",
            "note": null,
            "days_max": null,
            "days_min": null,
            "workflow": "incubation",
            "capability": "supports_incubation",
            "species_id": "01a16a3a-c87c-72c0-b34b-dc9347d3dac5",
            "approximate": false,
            "captured_at": "2026-10-23T19:29:28.230898Z",
            "species_code": "chicken",
            "no_automatic_reason": null,
            "automatic_expectation": true
        },
        "egg_stock": null,
        "parents": [
            {
                "id": "01a16a3d-5b20-700a-8e7b-efe3372aae8e",
                "role": "dam",
                "production_cycle_id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
                "head_count": null
            }
        ],
        "checks": [
            {
                "id": "01a16a3d-66f0-7033-95b7-c2b96b0649a3",
                "checked_on": "2026-10-08",
                "result": "positive",
                "fertile_count": 52,
                "notes": "Candled on day 7"
            }
        ],
        "outcomes": [],
        "result": null,
        "notes": null,
        "cancelled_at": null,
        "cancel_reason": null,
        "created_by": "01a16a3d-469d-726b-a767-762a8a2b5842",
        "created_at": "2026-10-23T19:29:28.000000Z",
        "updated_at": "2026-10-23T19:29:28.000000Z"
    },
    "meta": [],
    "message": "Check recorded."
}
```


The project name can be changed while it is active (`PATCH /breeding-projects/{id}` with `title`; blank returns to the generated name):

`PATCH /breeding-projects/01a16a3d-5ae9-738b-8b42-4925927f6222`

Request:

```json
{
    "title": "Layer house hatch, October"
}
```

Response `200`:

```json
{
    "data": {
        "id": "01a16a3d-5ae9-738b-8b42-4925927f6222",
        "reference": "BRD-2026-00001",
        "title": "Layer house hatch, October",
        "status": "active"
    },
    "_note": "only the fields this guide uses are shown"
}
```


## 4. Record the hatch or birth, and where the animals go

`POST /breeding-projects/{id}/outcomes` with `live_count`, optional `loss_count`, `recorded_at` (ISO-8601 with offset, between the start and now), `idempotency_key` and **`destination`**:

| `destination` | What happens | Default for |
|---|---|---|
| `new_batch` | A new batch of the same species is started on that day, named "Chicken hatch – 2026-10-22" ("Cattle offspring – ..." for pregnancy), and `live_count` is its starting head count. The mother batch does not change. | incubation |
| `mother_batch` | The animals are added to the mother batch's head count. | pregnancy |

Offer the choice with the default preselected (`default_destination` on the project says which). Incubation: `live_count + loss_count` cannot exceed `eggs_set`. `live_count: 0` records a failed attempt, adds nothing and creates no batch. Creating a new batch counts toward the plan's active-batch limit (see 4.3), and a worker may do it (`breeding.create` is enough).

With `new_batch` the farmer may also name the batch and pick its purpose: send `"new_batch": {"title": "...", "purpose": "..."}` (both optional; see 4.2). Left out, the name is generated and the purpose is the mother batch's.

The answer names the batch that received the animals in `destination_batch`; use its `id` to open it.

### 4.1 Hatch into a new batch (the incubation default)

`POST /breeding-projects/01a16a3d-5ae9-738b-8b42-4925927f6222/outcomes`

Request:

```json
{
    "live_count": 48,
    "loss_count": 12,
    "recorded_at": "2026-10-22T09:00:00+01:00",
    "idempotency_key": "hatch-001"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-6c5b-711d-b0f9-d5a6b7327c0c",
        "breeding_project_id": "01a16a3d-5ae9-738b-8b42-4925927f6222",
        "production_cycle_id": "01a16a3d-6b3e-7285-9089-95d6fa28465a",
        "destination": "new_batch",
        "destination_batch": {
            "id": "01a16a3d-6b3e-7285-9089-95d6fa28465a",
            "name": "Chicken hatch – 2026-10-22",
            "reference": "BAT-2026-00005",
            "status": "active",
            "current_population": 48
        },
        "kind": "outcome",
        "live_count": 48,
        "loss_count": 12,
        "outcome_date": "2026-10-22",
        "operational_record_id": null,
        "reverses_outcome_id": null,
        "corrects_outcome_id": null,
        "reversed_by_outcome_id": null,
        "reason": null,
        "recorded_at": "2026-10-22T08:00:00.000000Z",
        "notes": null,
        "created_by": "01a16a3d-469d-726b-a767-762a8a2b5842",
        "created_at": "2026-10-23T19:29:32.000000Z"
    },
    "meta": [],
    "message": "Outcome recorded."
}
```


The new batch, as `GET /production-cycles/{id}` returns it:

`GET /production-cycles/01a16a3d-6b3e-7285-9089-95d6fa28465a`

Response `200`:

```json
{
    "data": {
        "id": "01a16a3d-6b3e-7285-9089-95d6fa28465a",
        "name": "Chicken hatch – 2026-10-22",
        "reference": "BAT-2026-00005",
        "status": "active",
        "start_date": "2026-10-22",
        "end_date": null,
        "livestock": {
            "species": {
                "id": "01a16a3a-c87c-72c0-b34b-dc9347d3dac5",
                "code": "chicken",
                "name": "Chicken"
            },
            "breeding_workflow": "incubation",
            "initial_population": 48,
            "current_population": 48
        }
    },
    "meta": [],
    "message": null,
    "_note": "only the fields this guide uses are shown"
}
```


### 4.2 Name and purpose of the new batch

`new_batch` is only read when the animals go to a new batch (`destination: new_batch`, which is also the incubation default); sending it with `mother_batch`, or with `live_count: 0`, is `422`.

| Field | Rule | Default |
|---|---|---|
| `new_batch.title` | 1–100 characters, unique on the farm (closed batches count) | "Chicken hatch – {date}" / "Cattle offspring – {date}" |
| `new_batch.purpose` | a production-purpose code of the species, from the same list the batch form uses (`GET /master/species/{id}/batch-reference`) | the mother batch's purpose |

Show both as optional inputs on the hatch form: prefill the title with the generated name and the purpose with the mother's, and send only what the farmer changed. A title that is already in use is `409 duplicate_cycle_name`; an unknown purpose is `422` on `new_batch.purpose`.

`POST /breeding-projects/01a177f2-eddf-7225-8c83-30e6e1e7808b/outcomes`

Request:

```json
{
    "live_count": 37,
    "loss_count": 13,
    "destination": "new_batch",
    "new_batch": {
        "title": "October brooder A",
        "purpose": "eggs"
    },
    "recorded_at": "2026-10-26T10:22:50Z",
    "idempotency_key": "ecc5bef4-d384-4861-9464-5572f926f23e"
}
```

Response `201` (the batch carries the chosen name; its purpose is `eggs`):

```json
{
    "data": {
        "id": "01a177f2-efd2-7147-b20b-33c0ed934ce9",
        "breeding_project_id": "01a177f2-eddf-7225-8c83-30e6e1e7808b",
        "production_cycle_id": "01a177f2-ef1e-724e-837a-6af72789490c",
        "destination": "new_batch",
        "destination_batch": {
            "id": "01a177f2-ef1e-724e-837a-6af72789490c",
            "name": "October brooder A",
            "reference": "BAT-2026-00002",
            "status": "active",
            "current_population": 37
        },
        "kind": "outcome",
        "live_count": 37,
        "loss_count": 13,
        "outcome_date": "2026-10-26",
        "operational_record_id": null,
        "reverses_outcome_id": null,
        "corrects_outcome_id": null,
        "reversed_by_outcome_id": null,
        "reason": null,
        "recorded_at": "2026-10-26T10:22:50.000000Z",
        "notes": null,
        "created_by": "01a177f2-e9aa-720e-ab0c-8eb0d4c8b23f",
        "created_at": "2026-10-26T11:22:52.000000Z"
    },
    "meta": [],
    "message": "Outcome recorded."
}
```

Unknown purpose, `422`:

```json
{
    "message": "Choose an active code from the selected reference catalogue.",
    "code": "validation_failed",
    "request_id": "01a177f2-f22e-723d-97ba-35efd16453ba",
    "errors": {
        "new_batch.purpose": [
            "Choose an active code from the selected reference catalogue."
        ]
    }
}
```

### 4.3 The farm's plan has no room for another batch

A new batch counts toward the plan's active-batch limit. When it is full the outcome is **not** recorded (the project stays active) and the answer is `409 plan_limit_reached`. Its `message` tells the user the young can be added to the mother batch instead, and `details.alternative_destination` is `"mother_batch"`. Offer that as a button: resend the same form with `destination: "mother_batch"` (use a fresh `idempotency_key`; the refused attempt saved nothing).

`409` on `POST /breeding-projects/01a177f2-f1fb-72a3-898e-4b66a7c6d5d9/outcomes` (no `destination`, so the incubation default `new_batch`):

```json
{
    "message": "Your farm's plan allows 1 active production cycles and the limit has been reached. The young can be added to the mother batch instead: send destination \"mother_batch\".",
    "code": "plan_limit_reached",
    "request_id": "01a177f2-f351-736a-a7af-b623614ea6ea",
    "details": {
        "entitlement_key": "active_cycles",
        "limit": 1,
        "usage": 3,
        "remaining": 0,
        "alternative_destination": "mother_batch"
    }
}
```

### 4.4 Birth into the mother batch (the pregnancy default)

`POST /breeding-projects/01a16a3d-5d96-702b-b820-3a8cfe96ef9d/outcomes`

Request:

```json
{
    "live_count": 3,
    "loss_count": 1,
    "recorded_at": "2026-10-22T08:00:00+01:00",
    "idempotency_key": "calving-001"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-76b2-7338-89e7-474521d3beea",
        "breeding_project_id": "01a16a3d-5d96-702b-b820-3a8cfe96ef9d",
        "production_cycle_id": "01a16a3d-4f21-721e-87ed-18a252044547",
        "destination": "mother_batch",
        "destination_batch": {
            "id": "01a16a3d-4f21-721e-87ed-18a252044547",
            "name": "Dairy herd",
            "reference": "BAT-2026-00002",
            "status": "active",
            "current_population": 17
        },
        "kind": "outcome",
        "live_count": 3,
        "loss_count": 1,
        "outcome_date": "2026-10-22",
        "operational_record_id": "01a16a3d-7705-7019-8662-f8ed6470d53b",
        "reverses_outcome_id": null,
        "corrects_outcome_id": null,
        "reversed_by_outcome_id": null,
        "reason": null,
        "recorded_at": "2026-10-22T07:00:00.000000Z",
        "notes": null,
        "created_by": "01a16a3d-469d-726b-a767-762a8a2b5842",
        "created_at": "2026-10-23T19:29:35.000000Z"
    },
    "meta": [],
    "message": "Outcome recorded."
}
```


## 5. Undo

`POST /breeding-projects/{id}/outcomes/{outcome}/reverse` with `reason`, `recorded_at` and `idempotency_key` (needs `breeding.reverse`). Nothing is erased: a reversal row is added, the project becomes `active` again, and a corrected outcome can then be recorded with `corrects_outcome_id`.

* **Mother batch:** the animals are taken off the mother batch.
* **New batch:** the animals are taken off the new batch, and the empty batch is **closed** if nothing else was ever recorded for it. If it has other activity it stays open at 0 head. The mother batch is never touched.

`POST /breeding-projects/01a16a3d-5ae9-738b-8b42-4925927f6222/outcomes/01a16a3d-6c5b-711d-b0f9-d5a6b7327c0c/reverse`

Request:

```json
{
    "reason": "Entered the wrong count",
    "recorded_at": "2026-10-22T10:00:00+01:00",
    "idempotency_key": "hatch-undo-001"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-6feb-7133-b7c4-0f107dcd2b71",
        "breeding_project_id": "01a16a3d-5ae9-738b-8b42-4925927f6222",
        "production_cycle_id": "01a16a3d-6b3e-7285-9089-95d6fa28465a",
        "destination": "new_batch",
        "destination_batch": {
            "id": "01a16a3d-6b3e-7285-9089-95d6fa28465a",
            "name": "Chicken hatch – 2026-10-22",
            "reference": "BAT-2026-00005",
            "status": "closed",
            "current_population": 0
        },
        "kind": "reversal",
        "live_count": null,
        "loss_count": null,
        "outcome_date": null,
        "operational_record_id": "01a16a3d-7071-70f1-af34-6891571c620d",
        "reverses_outcome_id": "01a16a3d-6c5b-711d-b0f9-d5a6b7327c0c",
        "corrects_outcome_id": null,
        "reversed_by_outcome_id": null,
        "reason": "Entered the wrong count",
        "recorded_at": "2026-10-22T09:00:00.000000Z",
        "notes": null,
        "created_by": "01a16a3d-469d-726b-a767-762a8a2b5842",
        "created_at": "2026-10-23T19:29:33.000000Z"
    },
    "meta": [],
    "message": "Reversal recorded."
}
```


The new batch afterwards:

`GET /production-cycles/01a16a3d-6b3e-7285-9089-95d6fa28465a`

Response `200`:

```json
{
    "data": {
        "id": "01a16a3d-6b3e-7285-9089-95d6fa28465a",
        "name": "Chicken hatch – 2026-10-22",
        "reference": "BAT-2026-00005",
        "status": "closed",
        "start_date": "2026-10-22",
        "end_date": "2026-10-22",
        "livestock": {
            "species": {
                "id": "01a16a3a-c87c-72c0-b34b-dc9347d3dac5",
                "code": "chicken",
                "name": "Chicken"
            },
            "breeding_workflow": "incubation",
            "initial_population": 48,
            "current_population": 0
        }
    },
    "meta": [],
    "message": null,
    "_note": "only the fields this guide uses are shown"
}
```


A replacement may use either destination; a second new batch the same day gets a unique name ("... (2)").

`POST /breeding-projects/01a16a3d-5ae9-738b-8b42-4925927f6222/outcomes`

Request:

```json
{
    "live_count": 45,
    "loss_count": 15,
    "destination": "mother_batch",
    "corrects_outcome_id": "01a16a3d-6c5b-711d-b0f9-d5a6b7327c0c",
    "recorded_at": "2026-10-22T11:00:00+01:00",
    "idempotency_key": "hatch-002"
}
```

Response `201`:

```json
{
    "data": {
        "id": "01a16a3d-73e6-704a-bcc2-e3ce18203ec9",
        "destination": "mother_batch",
        "destination_batch": {
            "id": "01a16a3d-4aa2-70e2-b331-7b74313ada7b",
            "name": "Layer house 1",
            "reference": "BAT-2026-00001",
            "status": "active",
            "current_population": 165
        },
        "kind": "outcome",
        "live_count": 45,
        "operational_record_id": "01a16a3d-744e-7265-be3a-746cedcb4afe",
        "corrects_outcome_id": "01a16a3d-6c5b-711d-b0f9-d5a6b7327c0c"
    },
    "_note": "only the fields this guide uses are shown"
}
```


## 6. Errors

| Case | Response |
|---|---|
| Batch cannot breed / wrong workflow | `422` "validation_failed" - This species does not support the incubation breeding workflow. |
| More females than the batch holds | `422` "validation_failed" - The females bred (20) is more than the 14 head the batch holds now. |
| Parent count above its batch | `422` "validation_failed" - The parent head count (5) is more than the 2 head the batch holds now. |
| Dam is not the mother batch | `422` "validation_failed" - The dam is the mother batch (production_cycle_id); send the same batch or leave the dam out. |
| Unknown `new_batch.purpose` | `422` "validation_failed" - Choose an active code from the selected reference catalogue. (field `new_batch.purpose`) |
| Plan has no room for a new batch | `409` "plan_limit_reached" - the message says to add the young to the mother batch instead (`destination: "mother_batch"`); `details.alternative_destination` = `mother_batch` |
| New batch with 0 live | `422` "validation_failed" - A new batch needs at least one live offspring. |
| Undo after the animals left the batch | `409` "insufficient_population" - This event would produce a negative population, including in the dated ledger history. (`409`: show "Some of these animals have already been sold, died or moved") |
| Worker tries to undo | `403` `forbidden` |

Other errors you may meet: `409 project_not_active` (outcome recorded or project cancelled), `409 cycle_closed`, `409 idempotency_conflict`, `409 insufficient_stock` (eggs taken from stock), `404` for another farm's ids. After any save refetch the batch (`current_population`) and the dashboard.
