# Dashboard and insights — Phase 16

All paths have prefix `/api/v1`. Use the existing Sanctum SPA session, CSRF setup and optional `X-Farm-Id` active-membership selector. The farm is resolved server-side; there is no `farm_id` input. Machine-readable schemas are in `openapi.json`.

The dashboard is a **read model**. It stores nothing and owns no business state: every number is read live from the module that owns it (production cycles and the population ledger, operational records, the stock ledger, health, breeding, tasks, the finance ledger, invoices and payments). Reversed and cancelled events never count.

| Endpoint | Permission | Purpose |
|---|---|---|
| `GET /dashboard` | any active member | the whole command centre for this farm and this viewer |
| `GET /dashboard/calendar` | `task.view` | per-day task/milestone summary (default: the 7-day strip) |
| `GET /insights` | any active member | the full insight list (the dashboard embeds the top 5) |

Each block of the dashboard is additionally gated by the viewer's own permissions, so one endpoint serves every role without hard-coded role names.

## Relevance: only cards that matter

A card appears only when it is **relevant to what the farm runs** and **visible to the viewer**.

* **Operation-aware**: livestock content is relevant when the farm selected a livestock/aquaculture operation *or* has an active livestock cycle; crop content likewise. A crop-only farm never receives population, mortality, egg, withdrawal or breeding cards, and its Quick Record/Quick Add lists omit them. `operations` reports `configured`, the `selected` operations and `relevant: {livestock, crop}`.
* **Data-aware**: capability-specific cards need real data. `eggs_today` only exists when an active livestock cycle has a species with the `produces_eggs` capability; `active_withdrawals`/`active_breeding_projects` only when there is something active; money cards only when the farm has finance/sales/invoice data.
* **Role-aware** (permissions, not role names): money (finance, sales, receivables, payment/purchase/finance activity, `overdue_invoices`) needs `finance.view` / `sale.view` / `invoice.view` / `payment.view` / `purchase.view`; a Farm Worker or Vet never receives it. Production needs `production_cycle.view`, population/mortality/eggs/harvest need `record.view`, stock cards need `inventory.view`, health `health.view`, breeding `breeding.view`, work `task.view`.

## `GET /dashboard`

```json
{
  "data": {
    "farm": { "id": "…", "name": "Green Acres", "currency": "NGN", "timezone": "Africa/Lagos", "today": "2026-10-14", "generated_at": "2026-10-14T11:00:00.000000Z" },
    "viewer": { "user_id": "…", "role": "owner", "role_label": "Owner" },
    "operations": { "configured": true, "selected": [{ "id": "…", "code": "poultry", "name": "Poultry", "category": "livestock" }], "relevant": { "livestock": true, "crop": false } },
    "sections": { "production": true, "work": true, "calendar": true, "inventory": true, "health": true, "breeding": true, "finance": true, "sales": true },
    "priority": [{ "id": "mortality_threshold:…", "code": "mortality_threshold", "severity": "critical", "title": "…", "message": "…" }],
    "kpis": [
      { "code": "livestock_population", "label": "Livestock", "section": "livestock", "value": "92", "unit": "head", "breakdown": [], "detail": { "active_batches": 1 } },
      { "code": "eggs_today", "label": "Eggs today", "section": "livestock", "value": "300", "unit": "piece", "breakdown": [{ "unit": "piece", "quantity": "300" }], "detail": { "date": "2026-10-14" } },
      { "code": "net_month", "label": "Net this month", "section": "finance", "value": "0.25", "unit": "NGN", "breakdown": [], "detail": { "from": "2026-10-01", "to": "2026-10-14", "currency": "NGN" } }
    ],
    "work": { "counts": {}, "today_and_overdue": [], "upcoming": [], "recently_completed": [] },
    "calendar": { "from": "2026-10-14", "to": "2026-10-20", "timezone": "Africa/Lagos", "today": "2026-10-14", "days": [] },
    "quick_record": [{ "type": "mortality", "label": "Mortality", "kind": "livestock", "applies_to_cycles": 1 }],
    "production": { "total_active": 1, "items": [] },
    "insights": { "total": 3, "by_severity": { "critical": 1, "warning": 1, "info": 1 }, "items": [] },
    "recent_activity": [],
    "quick_add": [{ "code": "sale", "label": "Record a sale", "permission": "sale.create", "method": "POST", "endpoint": "/api/v1/sales" }],
    "empty_state": null
  },
  "meta": {}, "message": null
}
```

### KPI cards (`kpis`)

Every card is `{code, label, section, value, unit, breakdown, detail}`. `value` is a string (count, quantity or exact two-decimal money); `breakdown` lists `{unit, quantity}` when a measure has units — **quantities of different units are never added together**.

| code | Needs | Appears when | Value |
|---|---|---|---|
| `livestock_population` | `production_cycle.view` | livestock relevant | sum of ledger population of active livestock cycles (`head`); `detail.active_batches` |
| `mortality_7d` | `record.view` | an active livestock cycle exists | deaths over the last 7 farm-local days from non-reversed mortality records |
| `eggs_today` | `record.view` | an active cycle's species `produces_eggs` | today's egg collections (farm-local day), `piece` |
| `active_crop_projects` | `production_cycle.view` | crop relevant | count of active crop projects |
| `harvest_30d` | `record.view` | crop relevant | `value: null`, `breakdown` of harvest output over 30 days by normalised unit |
| `low_stock_items` | `inventory.view` | an item has a low-stock threshold | items at or below their threshold (stock ledger) |
| `active_withdrawals` | `health.view` | at least one running withdrawal | medicine lines still in withdrawal (event not reversed) |
| `active_breeding_projects` | `breeding.view` | at least one active project | count |
| `income_month`, `expense_month`, `net_month` | `finance.view` | the farm has finance transactions | farm-local month to date, from the same query as `GET /finance/summary` |
| `sales_month` | `sale.view` | the farm has sales | total of non-cancelled sales recorded this farm-local month; `detail.sales` is the count |
| `receivables` | `invoice.view` | the farm has an issued invoice | amount still owed on issued invoices (reversed payments do not count as received); `detail` has `invoices`, `overdue_invoices`, `overdue_outstanding` |

### Work (`work`, needs `task.view`)

The Phase 12 rules, for the tasks the viewer may see (managers see all; others their assigned/role/created/relevant-category tasks). `counts`: `overdue`, `due_today`, `upcoming`, `upcoming_7d`, `completed_today`. `today_and_overdue` (up to 10, by due time), `upcoming` (up to 5) and `recently_completed` (last 7 days, up to 5) are full task objects with `due_state`. The dashboard agrees with `GET /tasks?due_state=…`. Nothing here creates a record: tasks are work that should happen.

### Calendar strip (`calendar`) and `GET /dashboard/calendar`

Query: `from` (farm-local `Y-m-d`, default today), `days` (1–31, default 7). Built from the Phase 12 calendar read model, so visibility and milestone permissions are identical.

```json
{ "from": "2026-10-14", "to": "2026-10-20", "timezone": "Africa/Lagos", "today": "2026-10-14",
  "days": [{ "date": "2026-10-14", "is_today": true,
             "tasks": { "open": 2, "overdue": 1, "due_today": 1, "upcoming": 0, "completed": 1 },
             "milestones": [{ "code": "cycle_expected_end", "title": "…", "date": "2026-10-17", "end_date": null, "source": { "type": "production_cycle", "id": "…", "reference": "BAT-2026-00001" } }] }] }
```

Cancelled tasks are not counted. A window milestone (`breeding_expected_window`) appears on every day it covers. Validation: `days` outside 1–31 or a malformed `from` is 422.

### Production (`production`, needs `production_cycle.view`)

`total_active` and up to 6 active cycles (newest first): `kind`, operation, production area, dates, `age_days`, and either `livestock` (`species`, `initial_population`, `current_population` from the population ledger, `deaths_7d`) or `crop` (`crop_type`, `initial_planting_units`, `harvest_30d` by unit). Closed cycles are excluded. Planting units are never treated as material quantity.

### Quick Record and Quick Add

* `quick_record` (needs `record.create`): up to 6 record types that apply to the farm's *active* cycles — by cycle kind and by species capability (no `egg_collection` without an egg-producing species, no livestock types on a crop-only farm). Each has `applies_to_cycles`.
* `quick_add`: create actions the viewer's permissions allow and the farm's operations make relevant (`health_record` needs a livestock cycle, `breeding_project` a species with `supports_breeding`). Each has the `permission`, `method` and `endpoint` to call.

### Recent activity (`recent_activity`)

Up to 15 events, newest first, assembled live from the authoritative records — there is no activity table. Sources and the permission each needs: operational records (`record.view`), health records (`health.view`), cycle created/closed/reopened (`production_cycle.view`), completed tasks (`task.view`, the viewer's visible tasks), sales (`sale.view`), payments (`payment.view`), purchases (`purchase.view`), manual income/expense (`finance.view`). Reversed events and their correcting rows, cancelled sales/purchases and reversed payments are left out; sale/purchase/payment income rows are not repeated as finance entries. Item: `{kind, id, title, summary, occurred_at, actor {id, name}, production_cycle {id, name, reference}, subject {type, id, reference}}`.

### Empty state

When no cycle is active, `empty_state` is `{has_operations, has_active_production: false, message, suggested_actions}` (production-cycle and stock-in actions the viewer may perform); otherwise it is `null`. A brand-new farm receives `kpis: []`, zero counts and no insights — never a wall of zero cards.

## Insights — `GET /insights`

Query: `severity` (`critical`|`warning`|`info`), `limit` (1–50, default 50). Response: `data` is the list, `meta` is `{total, by_severity, today, timezone}`. Ordered critical → warning → info, then rule, then subject. Insights are **deterministic rules over existing data**: no model, no scoring, no stored state, same data gives the same insight. Each carries:

```json
{ "id": "mortality_threshold:<cycle id>", "code": "mortality_threshold", "severity": "warning",
  "title": "Mortality is above the threshold", "message": "Broilers A: 2 deaths in the last 7 days (2% of the opening population).",
  "why": { "rule": "mortality_threshold", "window_days": 7, "from": "2026-10-08", "to": "2026-10-14", "deaths": 2, "opening_population": 100, "rate_percent": "2",
           "thresholds": { "min_deaths": 2, "warning_percent": "2", "critical_percent": "5" } },
  "subject": { "type": "production_cycle", "id": "…", "reference": "BAT-2026-00001", "name": "Broilers A" }, "data": {} }
```

| Rule (`code`) | Fires when | Severity | Needs |
|---|---|---|---|
| `mortality_threshold` | active livestock cycle: ≥ 2 deaths in 7 farm-local days (reversed deaths excluded) AND rate ≥ 2% of the population when the window opened (current + deaths if the cycle is younger than the window) | warning; critical at ≥ 5% | `record.view` |
| `low_stock` | active item with a threshold whose ledger balance is ≤ it (max 5, lowest first) | warning; critical when the balance is ≤ 0 | `inventory.view` |
| `lot_expiry` | a lot with stock left expires within 14 days or has expired (max 5) | warning; critical when already expired | `inventory.view` |
| `overdue_tasks` | ≥ 1 visible open task past due | warning; critical at ≥ 5 | `task.view` |
| `overdue_invoices` | issued invoices past `due_date` with a balance | warning | `invoice.view` |
| `medicine_withdrawal` | a medicine line (event not reversed) is still inside its withdrawal period | info | `health.view` |
| `breeding_due_soon` / `breeding_overdue` | active project's stored expected date/window starts within 7 days / ended before today (max 5) | info / warning | `breeding.view` |
| `cycle_past_expected_end` | active cycle whose `expected_end_date` has passed (max 5) | info | `production_cycle.view` |

Thresholds are constants of the rule set (documented in `why.thresholds`), not farm settings. The mortality rate uses exact decimal arithmetic (two decimals at most).

## Time, units and money

* **Timezone**: every day boundary ("today", "last 7 days", "this month", task due state) is a farm-local calendar day (default `Africa/Lagos`) converted to UTC instants; `farm.today` tells the client which day the server used. 23:30 UTC on 14 Oct is already 15 Oct in Lagos.
* **Units**: quantities come from the normalised measurements already stored on records and the stock ledger; units are never mixed (`breakdown`).
* **Money**: exact two-decimal strings (`"0.30"`, never floats), taken from the finance, sales and payment ledgers; currency is the farm's.

## Errors

| Code | When |
|---|---|
| 401 | not signed in |
| 403 | `GET /dashboard/calendar` without `task.view` |
| 422 | invalid `from`, `days`, `severity` or `limit` |

`GET /dashboard` and `GET /insights` never return 403: a viewer simply receives the blocks they may see (`sections` says which).

## Performance

A dashboard request runs a fixed number of queries (48 in the test fixture, including authentication) that does not grow with the number of cycles, tasks, items or records: facts are computed once per request, aggregates run in SQL, and per-cycle figures are batched. No caching is used; Phase 21 owns final performance hardening.
