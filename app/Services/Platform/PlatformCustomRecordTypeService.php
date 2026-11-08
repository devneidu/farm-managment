<?php

namespace App\Services\Platform;

use App\Models\CustomRecordType;
use App\Models\OperationalRecord;
use App\Models\User;
use App\Services\Records\CustomRecordTypeService;
use App\Support\Api\ApiHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform view of farm-made record types: what farms invented (name, fields, how many farms and records use it - never the records), promoting a
 * farm's type to a public one for every farm, and archiving a public type. Making public COPIES: the farm's own type stays private and unchanged.
 */
class PlatformCustomRecordTypeService
{
    public function __construct(private CustomRecordTypeService $types, private PlatformAudit $audit) {}

    /** @param  array{source?: string, status?: string, applies_to?: string, q?: string, per_page?: int}  $f */
    public function list(array $f): LengthAwarePaginator
    {
        return $this->query()
            ->where('source', $f['source'] ?? CustomRecordType::FARM)
            ->when(isset($f['status']), fn (Builder $q) => $q->where('status', $f['status']))
            ->when(isset($f['applies_to']), fn (Builder $q) => $q->where('applies_to', $f['applies_to']))
            ->when(isset($f['q']), fn (Builder $q) => $q->where('name', 'like', '%'.PlatformPlanService::escapeLike($f['q']).'%'))
            ->orderByDesc('created_at')->orderBy('name')->paginate($f['per_page'] ?? 25);
    }

    public function find(string $id): CustomRecordType
    {
        return $this->query()->findOrFail($id);
    }

    /** The type with who made it and its usage counts (counts only: the records themselves are never exposed here). */
    private function query(): Builder
    {
        return CustomRecordType::query()->select('custom_record_types.*')->with(['farm:id,name', 'species:id,name', 'cropType:id,name'])->addSelect([
            'records_count' => OperationalRecord::query()->selectRaw('count(*)')->whereColumn('custom_record_type_id', 'custom_record_types.id'),
            'farms_using' => OperationalRecord::query()->selectRaw('count(distinct farm_id)')->whereColumn('custom_record_type_id', 'custom_record_types.id'),
            'copies_count' => CustomRecordType::query()->from('custom_record_types as copies')->selectRaw('count(*)')->whereColumn('copies.copied_from_id', 'custom_record_types.id'),
            'public_copy_id' => CustomRecordType::query()->from('custom_record_types as pub')->select('pub.id')->whereColumn('pub.copied_from_id', 'custom_record_types.id')->whereNull('pub.farm_id')->limit(1),
        ]);
    }

    /** A farm-made type -> a public copy (hidden fields dropped, version 1). The farm's original is untouched. */
    public function makePublic(User $actor, string $id): CustomRecordType
    {
        $public = DB::transaction(function () use ($actor, $id) {
            $original = CustomRecordType::query()->whereNotNull('farm_id')->lockForUpdate()->findOrFail($id);
            if ($original->status !== CustomRecordType::ACTIVE) {
                throw new ApiHttpException(409, 'type_archived', 'An archived record type cannot be made public.');
            }
            if ($existing = CustomRecordType::query()->whereNull('farm_id')->where('copied_from_id', $original->id)->first()) {
                throw new ApiHttpException(409, 'already_public', 'A public copy of this record type already exists.', details: ['existing_id' => $existing->id]);
            }
            $fields = array_values(array_filter($original->fields, fn ($f) => ! $f['hidden']));
            if ($fields === []) {
                throw ValidationException::withMessages(['fields' => 'Every field of this record type is hidden, so there is nothing to publish.']);
            }
            $this->types->assertNameFree($original->name, null);
            $public = CustomRecordType::create([
                'farm_id' => null, 'name' => $original->name, 'description' => $original->description, 'applies_to' => $original->applies_to, 'species_id' => $original->species_id,
                'crop_type_id' => $original->crop_type_id, 'fields' => $fields, 'version' => 1, 'status' => CustomRecordType::ACTIVE, 'source' => CustomRecordType::PUBLIC,
                'copied_from_id' => $original->id, 'created_by' => $actor->id,
            ]);
            $this->audit->record($actor, 'platform.custom_record_type_made_public', 'custom_record_type', $public->id, $public->name, [], ['copied_from_id' => $original->id, 'fields' => count($fields)]);

            return $public;
        });

        return $this->find($public->id);
    }

    public function archive(User $actor, string $id): CustomRecordType
    {
        return $this->setStatus($actor, $id, CustomRecordType::ARCHIVED, 'platform.custom_record_type_archived', 'type_already_archived', 'This public record type is already archived.');
    }

    public function unarchive(User $actor, string $id): CustomRecordType
    {
        return $this->setStatus($actor, $id, CustomRecordType::ACTIVE, 'platform.custom_record_type_unarchived', 'type_not_archived', 'This public record type is not archived.');
    }

    /** Public types only: hidden from new records on every farm; records already made with it stay readable. */
    private function setStatus(User $actor, string $id, string $status, string $action, string $conflict, string $message): CustomRecordType
    {
        DB::transaction(function () use ($actor, $id, $status, $action, $conflict, $message) {
            $type = CustomRecordType::query()->whereNull('farm_id')->lockForUpdate()->findOrFail($id);
            if ($type->status === $status) {
                throw new ApiHttpException(409, $conflict, $message);
            }
            $before = ['status' => $type->status];
            $type->update(['status' => $status]);
            $this->audit->record($actor, $action, 'custom_record_type', $type->id, $type->name, $before, ['status' => $status]);
        });

        return $this->find($id);
    }
}
