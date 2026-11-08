<?php

namespace App\Services\Records;

use App\Enums\Permission;
use App\Models\CropType;
use App\Models\CustomRecordType;
use App\Models\OperationalRecord;
use App\Models\ProductionCycle;
use App\Models\Species;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Access\FarmContext;
use App\Support\Api\ApiHttpException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Farm-made record types: the farm's own types plus the public ones the platform curates.
 *
 * A custom type only ever describes WHAT a farmer wants to write down. It has no measurement, population, stock or money effect: those
 * belong to the built-in types, which this class never touches. Records of a type keep the schema `version` they were captured with.
 */
class CustomRecordTypeService
{
    /** Words that already mean something else in the records API, besides the registry codes and operation labels. */
    private const RESERVED = ['custom', 'reversal', 'breeding outcome', 'livestock sale', 'livestock exit', 'livestock move in', 'health', 'health record', 'eggs', 'milk', 'record', 'records'];

    public function __construct(private CustomRecordSchema $schema, private RecordTypeRegistry $types, private AuditLogger $audit) {}

    // ------------------------------------------------------------------ reading

    /**
     * The farm's own types and the public ones, each with how many of THIS farm's records use it.
     *
     * @param  array{include_archived?: bool, applies_to?: string, source?: string}  $f
     * @return Collection<int, CustomRecordType>
     */
    public function list(FarmContext $ctx, array $f = []): Collection
    {
        return CustomRecordType::query()->visibleTo($ctx->farm->id)
            ->when(! ($f['include_archived'] ?? false), fn (Builder $q) => $q->active())
            ->when(isset($f['applies_to']), fn (Builder $q) => $q->where('applies_to', $f['applies_to']))
            ->when(isset($f['source']), fn (Builder $q) => $q->where('source', $f['source']))
            ->with(['species:id,name', 'cropType:id,name'])
            ->withCount(['records as records_count' => fn ($q) => $q->where('farm_id', $ctx->farm->id)])
            ->orderBy('name')->get();
    }

    /** A type this farm may see (its own or public); anything else, including another farm's, is 404. */
    public function find(FarmContext $ctx, string $id): CustomRecordType
    {
        return CustomRecordType::query()->visibleTo($ctx->farm->id)->with(['species:id,name', 'cropType:id,name'])
            ->withCount(['records as records_count' => fn ($q) => $q->where('farm_id', $ctx->farm->id)])->findOrFail($id);
    }

    private function own(FarmContext $ctx, string $id): CustomRecordType
    {
        return CustomRecordType::query()->where('farm_id', $ctx->farm->id)->lockForUpdate()->findOrFail($id);
    }

    // ------------------------------------------------------------------ farm writes

    /** @param  array<string, mixed>  $data */
    public function create(FarmContext $ctx, User $actor, array $data): CustomRecordType
    {
        $fields = $this->schema->normalize($data['fields']);
        $scope = $this->scopeColumns($data);

        $type = $this->guardDuplicate(fn () => DB::transaction(function () use ($ctx, $actor, $data, $fields, $scope) {
            $this->assertNameFree($data['name'], $ctx->farm->id);
            $type = CustomRecordType::create($scope + [
                'farm_id' => $ctx->farm->id, 'name' => $this->cleanName($data['name']), 'description' => $data['description'] ?? null, 'fields' => $fields,
                'version' => 1, 'status' => CustomRecordType::ACTIVE, 'source' => CustomRecordType::FARM, 'created_by' => $actor->id,
            ]);
            $this->audit->record($ctx->farm->id, $actor->id, 'custom_record_type.created', 'custom_record_type', $type->id, $type->name, ['fields' => count($fields), 'applies_to' => $type->applies_to]);

            return $type;
        }));

        return $this->find($ctx, $type->id);
    }

    /**
     * Name, description, who it applies to and the fields. Once records exist only the edits that keep their meaning are accepted (see
     * CustomRecordSchema::assertEditableWithRecords), and who the type applies to is frozen. A change to the fields bumps `version`.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FarmContext $ctx, User $actor, string $id, array $data): CustomRecordType
    {
        $type = $this->guardDuplicate(fn () => DB::transaction(function () use ($ctx, $actor, $id, $data) {
            $type = $this->own($ctx, $id);
            $inUse = OperationalRecord::where('custom_record_type_id', $type->id)->exists();
            $changes = [];

            if (isset($data['name']) && $this->cleanName($data['name']) !== $type->name) {
                $this->assertNameFree($data['name'], $ctx->farm->id, $type->id);
                $changes['name'] = [$type->name, $this->cleanName($data['name'])];
                $type->name = $this->cleanName($data['name']);
            }
            if (array_key_exists('description', $data) && $data['description'] !== $type->description) {
                $type->description = $data['description'];
                $changes['description'] = true;
            }
            if (array_intersect(['applies_to', 'species_id', 'crop_type_id'], array_keys($data)) !== []) {
                $scope = $this->scopeColumns($data + $type->only(['applies_to', 'species_id', 'crop_type_id']));
                if ($scope !== $type->only(['applies_to', 'species_id', 'crop_type_id'])) {
                    if ($inUse) {
                        throw new ApiHttpException(409, 'custom_type_in_use', 'Records already use this type, so who it applies to cannot change. Make a new type instead.', details: ['rule' => 'applies_to']);
                    }
                    $type->fill($scope);
                    $changes['applies_to'] = $scope['applies_to'];
                }
            }
            if (isset($data['fields'])) {
                $fields = $this->schema->normalize($data['fields'], $type->fields);
                if ($fields !== $type->fields) {
                    if ($inUse) {
                        $this->schema->assertEditableWithRecords($type->fields, $fields);
                    }
                    $type->fields = $fields;
                    $type->version++;
                    $changes['fields'] = ['version' => $type->version, 'count' => count($fields)];
                }
            }
            if ($changes !== []) {
                $type->save();
                $this->audit->record($ctx->farm->id, $actor->id, 'custom_record_type.updated', 'custom_record_type', $type->id, $type->name, $changes);
            }

            return $type;
        }));

        return $this->find($ctx, $type->id);
    }

    public function archive(FarmContext $ctx, User $actor, string $id): CustomRecordType
    {
        return $this->setStatus($ctx, $actor, $id, CustomRecordType::ARCHIVED, 'custom_record_type.archived', 'type_already_archived', 'This record type is already archived.');
    }

    public function unarchive(FarmContext $ctx, User $actor, string $id): CustomRecordType
    {
        return $this->setStatus($ctx, $actor, $id, CustomRecordType::ACTIVE, 'custom_record_type.unarchived', 'type_not_archived', 'This record type is not archived.');
    }

    private function setStatus(FarmContext $ctx, User $actor, string $id, string $status, string $action, string $conflict, string $message): CustomRecordType
    {
        $type = DB::transaction(function () use ($ctx, $actor, $id, $status, $action, $conflict, $message) {
            $type = $this->own($ctx, $id);
            if ($type->status === $status) {
                throw new ApiHttpException(409, $conflict, $message);
            }
            $type->update(['status' => $status]);
            $this->audit->record($ctx->farm->id, $actor->id, $action, 'custom_record_type', $type->id, $type->name);

            return $type;
        });

        return $this->find($ctx, $type->id);
    }

    /** A private copy of a public type: same fields and audience, version 1, owned (and editable) by this farm. The public type is untouched. */
    public function copy(FarmContext $ctx, User $actor, string $id, ?string $name): CustomRecordType
    {
        $public = CustomRecordType::query()->whereNull('farm_id')->active()->findOrFail($id);
        $name ??= $public->name;

        $copy = $this->guardDuplicate(fn () => DB::transaction(function () use ($ctx, $actor, $public, $name) {
            $this->assertNameFree($name, $ctx->farm->id);
            $copy = CustomRecordType::create([
                'farm_id' => $ctx->farm->id, 'name' => $this->cleanName($name), 'description' => $public->description, 'applies_to' => $public->applies_to,
                'species_id' => $public->species_id, 'crop_type_id' => $public->crop_type_id, 'fields' => $public->fields, 'version' => 1,
                'status' => CustomRecordType::ACTIVE, 'source' => CustomRecordType::FARM, 'copied_from_id' => $public->id, 'created_by' => $actor->id,
            ]);
            $this->audit->record($ctx->farm->id, $actor->id, 'custom_record_type.copied', 'custom_record_type', $copy->id, $copy->name, ['copied_from_id' => $public->id]);

            return $copy;
        }));

        return $this->find($ctx, $copy->id);
    }

    // ------------------------------------------------------------------ names

    public function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Unique among this farm's types (archived ones too) and never a built-in record type's code or label.
     *
     * @throws ValidationException built-in name
     * @throws ApiHttpException 409 duplicate_name
     */
    public function assertNameFree(string $name, ?string $farmId, ?string $exceptId = null): void
    {
        $normalized = CustomRecordType::normalizeName($name);
        if (in_array($normalized, $this->reservedNames(), true)) {
            throw ValidationException::withMessages(['name' => 'This name is already used by a built-in record type. Choose another.']);
        }
        $existing = CustomRecordType::query()
            ->when($farmId === null, fn ($q) => $q->whereNull('farm_id'), fn ($q) => $q->where('farm_id', $farmId))
            ->where('normalized_name', $normalized)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();
        if ($existing) {
            throw new ApiHttpException(409, 'duplicate_name', $existing->status === CustomRecordType::ARCHIVED
                ? 'An archived record type with this name already exists; unarchive it instead.'
                : 'A record type with this name already exists.', details: ['existing_id' => $existing->id, 'source' => $existing->source, 'status' => $existing->status]);
        }
    }

    /** Normalised codes and labels of every built-in record type (codes with spaces, e.g. "feed use"), plus a few words the API already uses. */
    public function reservedNames(): array
    {
        $names = self::RESERVED;
        foreach (array_keys($this->types->definitions()) as $code) {
            $names[] = str_replace('_', ' ', $code);
            $names[] = $code;
        }
        foreach (OperationCatalogue::LABELS as $label) {
            $names[] = $label;
        }

        return array_values(array_unique(array_map([CustomRecordType::class, 'normalizeName'], $names)));
    }

    private function guardDuplicate(callable $work): mixed
    {
        try {
            return $work();
        } catch (UniqueConstraintViolationException) {
            throw new ApiHttpException(409, 'duplicate_name', 'A record type with this name already exists.');
        }
    }

    // ------------------------------------------------------------------ who it applies to

    /**
     * applies_to plus the optional species / crop filter, validated (422).
     *
     * @param  array<string, mixed>  $data
     * @return array{applies_to: string, species_id: ?string, crop_type_id: ?string}
     */
    private function scopeColumns(array $data): array
    {
        $appliesTo = $data['applies_to'] ?? null;
        $species = $data['species_id'] ?? null;
        $crop = $data['crop_type_id'] ?? null;
        if ($species !== null && $appliesTo !== 'livestock') {
            throw ValidationException::withMessages(['species_id' => 'A species can only be chosen for a livestock record type.']);
        }
        if ($crop !== null && $appliesTo !== 'crop') {
            throw ValidationException::withMessages(['crop_type_id' => 'A crop can only be chosen for a crop record type.']);
        }
        if ($species !== null && ! Species::whereKey($species)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['species_id' => 'This species is not available.']);
        }
        if ($crop !== null && ! CropType::whereKey($crop)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['crop_type_id' => 'This crop is not available.']);
        }

        return ['applies_to' => $appliesTo, 'species_id' => $species, 'crop_type_id' => $crop];
    }

    /** Why the type cannot be recorded against this cycle, or null. */
    public function inapplicableReason(CustomRecordType $type, ProductionCycle $cycle): ?string
    {
        $kind = $cycle->kind->value;
        if ($type->applies_to !== 'both' && $type->applies_to !== $kind) {
            return $type->applies_to === 'livestock' ? 'This record type is for livestock batches, not crop projects.' : 'This record type is for crop projects, not livestock batches.';
        }
        if ($kind === 'livestock' && $type->species_id !== null && $type->species_id !== $cycle->livestock?->species_id) {
            return 'This record type is only for '.($type->species?->name ?? 'one species').'.';
        }
        if ($kind === 'crop' && $type->crop_type_id !== null && $type->crop_type_id !== $cycle->crop?->crop_type_id) {
            return 'This record type is only for '.($type->cropType?->name ?? 'one crop').'.';
        }

        return null;
    }

    // ------------------------------------------------------------------ recording

    /**
     * The type a new record is written against: visible to the farm, active, and a match for the cycle. Every failure is the same 422 on
     * `custom_record_type_id`, so another farm's private type is indistinguishable from one that does not exist.
     */
    public function forRecording(string $farmId, ProductionCycle $cycle, string $id): CustomRecordType
    {
        $type = CustomRecordType::query()->visibleTo($farmId)->with(['species:id,name', 'cropType:id,name'])->find($id);
        if ($type === null) {
            $this->invalid('This record type does not exist.');
        }
        if ($type->status !== CustomRecordType::ACTIVE) {
            $this->invalid('This record type is archived. Unarchive it before recording with it.');
        }
        if ($reason = $this->inapplicableReason($type, $cycle)) {
            $this->invalid($reason);
        }

        return $type;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['custom_record_type_id' => $message]);
    }

    /**
     * Active types a farm can record right now: its own plus the public ones it has not copied. Optionally only those that fit a cycle.
     *
     * @return Collection<int, CustomRecordType>
     */
    public function recordable(string $farmId, ?ProductionCycle $cycle = null): Collection
    {
        $copied = CustomRecordType::query()->where('farm_id', $farmId)->active()->whereNotNull('copied_from_id')->pluck('copied_from_id')->all();
        $types = CustomRecordType::query()->visibleTo($farmId)->active()->with(['species:id,name', 'cropType:id,name'])
            ->whereNotIn('id', $copied)->orderBy('name')->get();

        return $cycle === null ? $types : $types->filter(fn ($t) => $this->inapplicableReason($t, $cycle) === null)->values();
    }

    /** @return array{always: list<string>, when_inventory_linked: list<string>, when_correcting: list<string>} */
    public function permissionsRequired(): array
    {
        return ['always' => [Permission::RecordCreate->value], 'when_inventory_linked' => [], 'when_correcting' => [Permission::RecordReverse->value]];
    }

    /** The entry of GET /master/record-types for one custom type: the built-in schema shape plus the field list the form is built from. */
    public function schemaEntry(CustomRecordType $type): array
    {
        // A readable summary of what each visible field accepts (the real rules are fixed per kind and live in CustomRecordSchema).
        $rules = [];
        foreach ($this->visibleFields($type) as $f) {
            $rules[$f['key']] = [$f['required'] ? 'required' : 'nullable', ...match ($f['kind']) {
                CustomRecordSchema::NUMBER => array_values(array_filter(['decimal', ($f['min'] ?? null) !== null ? 'min:'.$f['min'] : null, ($f['max'] ?? null) !== null ? 'max:'.$f['max'] : null])),
                CustomRecordSchema::WHOLE_NUMBER => ['whole_number', 'min:0'],
                CustomRecordSchema::SHORT_TEXT => ['string', 'max:200'],
                CustomRecordSchema::LONG_TEXT => ['string', 'max:2000'],
                CustomRecordSchema::DATE => ['date_format:Y-m-d'],
                CustomRecordSchema::YES_NO => ['boolean'],
                CustomRecordSchema::CHOOSE_ONE => ['in:'.implode(',', $f['options'])],
            }];
        }

        return [
            'type' => 'custom', 'custom_record_type_id' => $type->id, 'name' => $type->name, 'description' => $type->description, 'source' => $type->source,
            'version' => $type->version, 'cycle_kind' => $type->applies_to === 'both' ? null : $type->applies_to, 'applies_to' => $type->applies_to,
            'species_id' => $type->species_id, 'crop_type_id' => $type->crop_type_id, 'capability' => null,
            'fields' => $rules, 'custom_fields' => $this->visibleFields($type), 'measurement' => null, 'area_fields' => [], 'population_effect' => 'none',
            'permission' => Permission::RecordCreate->value, 'permissions_required' => $this->permissionsRequired(),
            'inventory_effect_enabled' => false, 'inventory_category' => null, 'inventory_direction' => null, 'inventory_required' => false,
            'inventory_dimensions' => null, 'inventory_automatic' => false, 'inventory_output' => null, 'inventory' => null,
        ];
    }

    /** The fields a form shows: visible ones, in order. */
    public function visibleFields(CustomRecordType $type): array
    {
        return array_values(array_filter($type->fields, fn ($f) => ! $f['hidden']));
    }
}
