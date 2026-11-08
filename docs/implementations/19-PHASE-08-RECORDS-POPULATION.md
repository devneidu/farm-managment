# Phase 8 --- Operational records and population

## Objective

Capture real-world farm events and derive population safely.

## Backend scope

Canonical operational record service, type-specific validators/detail
payloads, attachments, population movements. Implement feed use, egg
collection, milk, mortality, weight, temperature, water and core crop
activity shells.

## API/UI contract

`GET|POST /records`, schema endpoint, reverse endpoint, attachments.

## Critical business rules

Mortality reduces population; egg collection increases output stock only
when inventory integration is enabled; records have `recorded_at`;
corrections use reversal/adjustment.

## Tests / acceptance

Dynamic validation; insufficient population; idempotent side effects;
attachment policy; reversal restores derived effects.

## Stop condition

Do not proceed to the next phase until migrations are reversible,
automated tests pass, tenant/farm isolation is verified, and this
phase's acceptance criteria are met.

## Addendum --- farm-made record types

Additive; built-in record types, the static registry, population and stock
rules are unchanged.

-   Table `custom_record_types` (farm_id NULL = public; unique name per farm
    and among public types; never a built-in code/label; `applies_to`
    livestock|crop|both with optional species / crop filter; JSON `fields`,
    `version`, `status` active|archived, `source` farm|public,
    `copied_from_id`). `operational_records` gains nullable
    `custom_record_type_id` and `custom_schema_version`; `type` is the fixed
    string `custom` (ids never go into the 40-char type string).
-   Field schema: at most 15 fields; kinds number (unit, min, max),
    whole_number, short_text, long_text, date, yes_no, choose_one; `required`,
    `hidden`. A server-side converter maps each kind to a fixed Laravel rule
    set (no client rules); the details whitelist is exactly the visible keys.
-   Endpoints `/custom-record-types` (list, create, show, patch, archive,
    unarchive, copy from public) with `master_data.view` / `master_data.manage`.
    Once records exist: add fields, rename labels, add options, required to
    optional, reorder, hide and widen limits are allowed; deleting a field,
    changing its kind, removing an option, optional to required, changing a
    unit, narrowing limits and changing who the type applies to are `409
    custom_type_in_use`. Version bumps on a field change; records keep their
    `custom_schema_version`.
-   Recording: `POST /records` with `type: custom` + `custom_record_type_id`;
    the type must be visible, active and match the batch. No measurement,
    population, stock or money effect. Included in the record-type catalogue,
    operations catalogue (display mode normal), `available_record_types`,
    quick record and `GET /records` filtering. Report `custom_records`.
-   Tests: `tests/Feature/Records/CustomRecordTypesTest.php`,
    `tests/Feature/Reports/CustomRecordsReportTest.php`.
