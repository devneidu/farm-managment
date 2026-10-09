# Livestock batch creation reference and acquisition metadata

Product-owner contract, 2026-10-09. Extends existing master data and production cycles; no new production workflow. All paths below use `/api/v1`, Sanctum SPA sessions, the usual `Origin` header and CSRF header on writes. Use `X-Farm-Id` when selecting an authorized farm.

## Selected animal reference

`GET /master/species/{species}/batch-reference` requires `master_data.view` (all farm roles). `{species}` is a UUID from `GET /master/species`. Optional `include_inactive=1` includes historical inactive choices; default is active choices only. No pagination: one animal's small selector lists are returned together. Other master endpoints remain supported.

```json
{
  "data": {
    "species": {"id":"<chicken-uuid>","code":"chicken","name":"Chicken","is_active":true,"livestock_group":{"code":"poultry","name":"Poultry"},"operation":{"id":"<poultry-uuid>","code":"poultry","name":"Poultry","category":"livestock","tracking_model":"population"},"capability_codes":["supports_group_tracking","supports_mortality","supports_feed_records","supports_live_weight","supports_breeding","produces_eggs","supports_incubation"]},
    "breed_field_label": "Breed / Strain",
    "breeds": [{"id":"<breed-uuid>","species_id":"<chicken-uuid>","code":"cobb_500","name":"Cobb 500","source":"system","is_active":true,"is_editable":false,"created_at":"2026-10-09T12:00:00.000000Z"}],
    "purposes": [{"code":"meat","name":"Meat","is_active":true}],
    "growth_stages": [{"code":"chick","name":"Chick","is_active":true}]
  },
  "meta": {},
  "message": null
}
```

Example lists shortened. Breeds include system rows and the current farm's custom rows, never another farm's. `POST /custom-breeds` and existing update/isolation rules remain supported. Render `breed_field_label` from the backend: Fish, Snail, Honeybee, Grasscutter, Camel, Quail and Ostrich use **Species / Type**; other supplied animals use **Breed / Strain**. These are farmer-facing options, not a biological taxonomy. Re-fetch all dependent lists and clear breed, purpose and stage selections when the species changes.

Purposes and stages reuse `reference_values`: lists `livestock_purpose_<species-code>` and `livestock_stage_<species-code>`, with UUIDv7 ids, stable codes, readable names, ordering and activation. Use existing platform `GET|POST /platform-admin/master/reference-values` and `GET|PATCH /platform-admin/master/reference-values/{id}`; filter `?list=livestock_purpose_chicken`. Platform admin/support can read; only admin can write. List and code cannot change after creation, deactivation keeps history, and changes use existing platform audit. `breed_field_label` is editable through existing platform species create/update APIs (nullable, maximum 64 characters). Platform administration can maintain future options; the supplied seed adds only the approved entries below.

## Creation contract

`POST /production-cycles` requires `production_cycle.create` (Owner/Manager), an active farm membership, CSRF, and available `active_cycles` capacity. Existing shared 60/hour/user write throttle remains. Crop bodies and behavior remain unchanged. Livestock/fish creation fields:

| Field | Validation |
|---|---|
| `kind` | Required `livestock` (crop follows its existing separate shape) |
| `name` | Required string, 1–100 characters after whitespace normalization; unique per farm/kind including closed cycles |
| `operation_type_id` | Required UUID, active operation using population tracking |
| `species_id` | Required UUID, active species of that operation |
| `breed_id` | Optional nullable UUID; active breed of that species, system or current farm |
| `production_purpose` | **Required on all new livestock batches**, active code from that species' purposes |
| `growth_stage` | Optional nullable active code from that species' stages |
| `acquisition_price_per_animal` | Optional nullable NGN amount: integer, numeric string or JSON number; 0 allowed, no negatives/exponents/booleans, at most two decimals and 16 integer digits; use strings for money |
| `supplier_contact_id` | Optional nullable UUID of an active current-farm contact with supplier role |
| `initial_population` | Required positive whole count, 1–999999999999; no derived balance supplied by client |
| `start_date` | Required calendar date `YYYY-MM-DD`; historical dates remain valid |
| `production_area_id` | Optional nullable UUID, active current-farm production area and ancestors |
| `expected_end_date` | Optional nullable calendar date, on or after start date |
| `notes` | Optional nullable string, maximum 5000 characters |

The four added fields are livestock-only (nonempty values are rejected on crop creation). Farm/id/reference/status/current_population/end_date/is_active/material_quantity remain server-owned and rejected. No client currency field is accepted as authoritative: acquisition price always means NGN, regardless of farm currency.

```json
{
  "kind": "livestock",
  "name": "October Broilers",
  "operation_type_id": "<poultry-uuid>",
  "species_id": "<chicken-uuid>",
  "breed_id": "<cobb-500-uuid>",
  "production_purpose": "meat",
  "growth_stage": "chick",
  "acquisition_price_per_animal": "1250.50",
  "supplier_contact_id": "<farm-supplier-uuid>",
  "initial_population": 500,
  "start_date": "2026-10-09",
  "production_area_id": null
}
```

Success is `201`, `{data: <existing ProductionCycleResource>, meta: {}, message: "Production cycle created."}`. Its `livestock` object adds:

```json
{
  "production_purpose": {"code":"meat","name":"Meat","is_active":true},
  "growth_stage": {"code":"chick","name":"Chick","is_active":true},
  "acquisition_price_per_animal": "1250.50",
  "acquisition_currency": "NGN",
  "supplier_contact": {"id":"<farm-supplier-uuid>","name":"Farm supplier","is_active":true}
}
```

Fields appear consistently on create, list, detail, summary and lifecycle responses. Missing optional fields are null; acquisition currency is null when there is no price. Existing pre-migration batches retain null values for **all** added fields, including purpose; no historical backfill or inference. Referenced inactive choices/contacts remain readable. A contact linked to a batch cannot lose its supplier role (`409 contact_in_use`); it may be deactivated. No phone/email/address is added to batch responses.

The new metadata joins the immutable starting baseline: `PATCH /production-cycles/{id}` containing any of these four fields returns `409 baseline_locked`, including null. Growth stage describes the stage at creation; it does not automatically advance. Purposes do not enable/disable biological capabilities or change breeding, feeding, mortality, health, output or sale rules.

## Effects and errors

Creation remains one transaction: cycle, immutable detail, one initial population movement and lifecycle event. Initial population remains ledger-derived, and `recorded_at` is start-of-day in the farm timezone converted to UTC. **No expense, purchase, payment, animal profile or inventory movement is created**, and an acquisition price is not included automatically in finance/profitability. No individual tracking is added.

`401 unauthenticated`, `403 forbidden`/farm-access/account/onboarding guards, `404 not_found` for unknown or foreign UUIDs, `409 duplicate_cycle_name`/`location_inactive`/`plan_limit_reached`, `419 session_expired`, `422 validation_failed`, `429 too_many_requests` follow existing API formats. Inactive/non-supplier own-farm contacts and inactive/mismatched species/breed/purpose/stage return 422. Invalid reference or contact selection leaves no partial batch or initial movement.

```json
{"message":"Choose an active code from the selected reference catalogue.","code":"validation_failed","request_id":"<request-uuid>","errors":{"growth_stage":["Choose an active code from the selected reference catalogue."]}}
```

The selected-animal GET additionally returns 404 for an unknown species and 422 for invalid query parameters. An inactive species may be read for history but cannot create a batch.

## Deployment and limits

Apply `2026_10_25_100000_extend_livestock_batch_creation` with a normal migration. It adds nullable foreign keys `production_purpose_id`, `growth_stage_id`, `supplier_contact_id` and `DECIMAL(18,2)` acquisition price to `livestock_batch_details`, plus nullable `species.breed_field_label`; runs `LivestockBatchReferenceSeeder` for the exact approved catalogue. The standalone seeder is safe to rerun after the migration: it inserts missing system rows, reuses uncoded same-name system breeds, preserves existing UUIDs/names/activation and custom breeds, and never overwrites administrator edits. Stable breed codes are generated from the exact name using underscore slugs, scoped by species. Rollback refuses to discard any used metadata; unused rollback leaves master records intact.

Deploy backend/schema and updated clients together: newly submitted livestock batches must send a purpose, with no implicit fallback. Fish, Snail and Honeybee still use the existing **head** population unit and integer ledger. Colony-specific accounting, individual animals, stage progression and automated acquisition accounting remain outside this task.

## Exact approved reference data

The catalogue below is supplied by the product owner; no additional breed, purpose or stage is seeded. Labels use readable code words (for example `queen_rearing` → `Queen rearing`). `dual_purpose` is shown with its two uses spelled out: `Dual purpose (eggs & meat)` for poultry and quail, `Dual purpose (milk & meat)` for cattle. The code is unchanged; an existing database is updated by migration `2026_10_26_100000` only where the name is still the old default, so a name an administrator edited is kept.

- Chicken: Local/Indigenous, Fulani, Noiler, FUNAAB Alpha, Shika-Brown, ISA Brown, Kuroiler, Sasso, Cobb 500, Ross 308, Marshall, Arbor Acres, Rhode Island Red, White Leghorn.
- Cattle: White Fulani (Bunaji), Red Bororo (Rahaji), Sokoto Gudali, Adamawa Gudali, Wadara, Azawak, Muturu, N'Dama, Kuri, Keteku, Holstein-Friesian, Jersey, Brahman.
- Goat: West African Dwarf, Red Sokoto (Maradi), Sahel, Kano Brown, Boer, Saanen, Anglo-Nubian, Alpine.
- Sheep: Yankasa, Uda, Balami, West African Dwarf, Dorper, Suffolk, Merino.
- Pig: Nigerian Indigenous, Large White (Yorkshire), Landrace, Duroc, Hampshire, Pietrain, Berkshire.
- Fish: African Catfish (Clarias gariepinus), Heterobranchus Catfish, Heteroclarias Hybrid, Nile Tilapia, Red Tilapia, Galilee Tilapia, Redbelly Tilapia, Common Carp, African Bonytongue.
- Turkey: Local/Indigenous, Broad Breasted White, Broad Breasted Bronze, Standard Bronze, Bourbon Red, Narragansett, Royal Palm, Beltsville Small White.
- Duck: Local/Indigenous, Muscovy, Pekin, Khaki Campbell, Indian Runner, Rouen.
- Guinea Fowl: Indigenous Helmeted Guinea Fowl, Pearl, Lavender/Ash, Black, White.
- Goose: Domestic/Unspecified, African Goose, Chinese Goose, Embden, Toulouse.
- Quail: Japanese Quail, Bobwhite Quail.
- Pigeon: Local/Domestic, King, Carneau, Mondain, Homer.
- Ostrich: Common Ostrich, Red-necked/North African, Masai, Somali.
- Rabbit: New Zealand White, Californian, Chinchilla, Dutch, Flemish Giant, Rex, Angora, Local/Mixed.
- Grasscutter: Greater Cane Rat (Thryonomys swinderianus).
- Camel: Dromedary (One-humped Camel).
- Water Buffalo: River Buffalo, Swamp Buffalo, Murrah, Nili-Ravi, Mediterranean Buffalo.
- Horse: Local/Nigerian Horse, Dongola, Arabian, Thoroughbred.
- Donkey: Local/Indigenous Donkey, African Donkey Type.
- Guinea Pig: American, Abyssinian, Peruvian, Teddy.
- Snail: Archachatina marginata, Achatina achatina, Lissachatina fulica.
- Honeybee: Apis mellifera adansonii.

These are farmer-facing breed/type options, not all strict biological breeds. Do not invent additional entries.

### Production purposes and growth stages

Create backend-managed, animal-specific options with stable codes.

| Animal | Purpose codes | Stage codes |
|---|---|---|
| Chicken | meat, eggs, dual_purpose, breeding, other | hatchling, chick, grower, adult, unknown |
| Turkey | meat, eggs, breeding, other | poult, grower, adult, unknown |
| Guinea Fowl | meat, eggs, dual_purpose, breeding, other | keet, grower, adult, unknown |
| Duck | meat, eggs, dual_purpose, breeding, other | duckling, grower, adult, unknown |
| Goose | meat, eggs, breeding, other | gosling, grower, adult, unknown |
| Quail | meat, eggs, dual_purpose, breeding, other | chick, grower, adult, unknown |
| Pigeon | meat, breeding, other | squab, juvenile, adult, unknown |
| Ostrich | meat, eggs, leather, feathers, breeding, other | chick, juvenile, adult, unknown |
| Goat | meat, dairy, fibre, hide, breeding, other | kid, weaner, grower, adult, unknown |
| Sheep | meat, dairy, wool, hide, breeding, other | lamb, weaner, grower, adult, unknown |
| Cattle | meat, dairy, dual_purpose, hide, breeding, draught, other | calf, weaner, grower, adult, unknown |
| Camel | meat, dairy, transport, breeding, other | calf, weaner, juvenile, adult, unknown |
| Water Buffalo | meat, dairy, draught, breeding, other | calf, weaner, grower, adult, unknown |
| Pig | meat, breeding, other | piglet, weaner, grower, finisher, adult, unknown |
| Rabbit | meat, breeding, fur, other | kit, weaner, grower, adult, unknown |
| Grasscutter | meat, breeding, other | pup, juvenile, adult, unknown |
| Guinea Pig | meat, breeding, other | pup, juvenile, adult, unknown |
| Horse | breeding, transport, recreation, work, other | foal, weanling, yearling, adult, unknown |
| Donkey | breeding, transport, work, other | foal, weanling, juvenile, adult, unknown |
| Snail | meat, breeding, other | hatchling, juvenile, adult, unknown |
| Honeybee | honey, beeswax, pollination, queen_rearing, colony_breeding, other | new_colony, developing_colony, established_colony, unknown |
| Fish | table_fish, fingerling_production, breeding, other | larva, fry, fingerling, juvenile, adult, unknown |

Use readable labels for each code. Keep purposes and stages species-specific.

Do not treat pregnancy, lactation, sex or breeding roles as biological growth stages.
