# Livestock catalogue and biological references

Durable source for Phase 11 (Breeding) and the frontend. The catalogue, grouping, capabilities and periods below are **product-owner decisions** applied by the migration `2026_10_09_100000_expand_livestock_catalogue` (via `LivestockCatalogueSeeder`). Fish/aquaculture (`fish`, operation `fishery`) is separate and unchanged.

## Reading it from the API

- `GET /api/v1/master/species` (optionally `?group=<code>`, `?operation=`, `?category=`): each species has `livestock_group: {code, name} | null` (null for fish), `operation` and `capability_codes`.
- `GET /api/v1/master/species/{id}/capabilities`: all 11 capabilities with `enabled` and `reference`.

## Groups

`species.livestock_group` (enum `App\Enums\LivestockGroup`) is a **presentation grouping only**; behaviour always comes from capabilities, never from the group. Operations are unchanged in meaning: poultry species share the `poultry` operation; every other species has its own operation (the existing Phase 4 pattern, because a cycle's operation is baseline-locked and its species must belong to it).

| Group (`code`) | Species (`code`) → operation |
|---|---|
| Poultry (`poultry`) | chicken, turkey, guinea_fowl, duck, goose, quail, pigeon, ostrich → `poultry` |
| Small ruminants (`small_ruminants`) | goat → `goat`, sheep → `sheep` |
| Large ruminants (`large_ruminants`) | cattle → `cattle`, camel → `camel`, water_buffalo → `water_buffalo` |
| Non-ruminant mammals (`non_ruminant_mammals`) | pig → `pig`, rabbit → `rabbit`, grasscutter → `grasscutter`, guinea_pig → `guinea_pig` |
| Equines (`equines`) | horse → `horse`, donkey → `donkey` |
| Micro-livestock (`micro_livestock`) | snail → `snail`, honeybee → `honeybee` |

21 livestock species. Phase 4 species (chicken, cattle, goat, sheep, pig, rabbit) were reused with their ids; new operations/species were added.

## Capabilities

All 21: `supports_group_tracking`, `supports_mortality`, `supports_feed_records`, `supports_breeding`. Also:

| Species | Reproduction capability | `produces_eggs` | `supports_live_weight` |
|---|---|---|---|
| Poultry (all 8) | `supports_incubation` (never pregnancy) | yes (all 8, including pigeon) | yes |
| Mammals (goat, sheep, cattle, camel, water buffalo, pig, rabbit, grasscutter, guinea pig, horse, donkey) | `supports_pregnancy` (never incubation) | no | yes |
| Snail | `supports_incubation` (no number) | no | yes |
| Honeybee | `supports_incubation` (range + caste note) | no | **no** |

`supports_individual_tracking`, `produces_milk` and `supports_harvest` (fish only) are not enabled for any livestock species; milk has no product decision yet.

## Biological reference values (`reference`)

Stored in `species_capabilities.reference_config` (JSON) and validated by `Capability::configRules()`. Keys for incubation use `incubation_days…`, for gestation `gestation_days…`:

| Key | Meaning |
|---|---|
| `<x>_days` | a single typical value (a default). **Present only when the source gives one value.** |
| `<x>_days_min`, `<x>_days_max` | inclusive range; only valid together, `max >= min`; a default, if also present, must lie inside |
| `approximate` | `true` when the source says "about" |
| `note` | short caveat |

**A range is never collapsed to a midpoint and no default is invented for it.** Choosing the single expected-date policy for a range (min, max, a farm choice…) is an explicit Phase 11 decision.

| Species | Type | Stored |
|---|---|---|
| Chicken | incubation | 21 |
| Turkey | incubation | 28 |
| Guinea fowl | incubation | 26–28 |
| Duck | incubation | 28 |
| Goose | incubation | 28–35 |
| Quail | incubation | 16–18 |
| Pigeon | incubation | 17–19 |
| Ostrich | incubation | 42 |
| Goat | gestation | 150, approximate |
| Sheep | gestation | 147–150, approximate |
| Cattle | gestation | 280–285, approximate; **plus the Phase 4 documented default 283** (kept, inside the range) |
| Camel | gestation | 365–400 |
| Water buffalo | gestation | 310–320, approximate |
| Pig | gestation | 114 |
| Rabbit | gestation | 30–32 |
| Grasscutter | gestation | 150–155, approximate |
| Guinea pig | gestation | 59–72 |
| Horse | gestation | 340, approximate |
| Donkey | gestation | 365 |
| Snail | incubation | **no number**; `note`: varies significantly by species |
| Honeybee | development/incubation | 16–24 (min/max), `note`: depends on caste; no default |

Honeybee's caste-dependent meaning is preserved only as the 16–24 range plus the note. There is no queen/worker/drone model.

## These are references, not outcomes

They are biological **reference defaults**, never guarantees. Phase 11 must keep three things separate: the *biological reference* (this data), the *expected* date/outcome (computed per event, with an explicit policy), and the *actual* date/outcome (what happened). Nothing here creates an event, task or record.

## Existing databases

The migration is additive and idempotent: existing rows are never deleted or recreated; a missing group is filled; an existing reference config only gains keys it lacks (a platform edit is kept, and a conflicting edit makes that species keep its value); a disabled capability is not re-enabled. Re-running changes nothing. `down()` removes only the `livestock_group` column and keeps the added master data, which cycles may already reference.
