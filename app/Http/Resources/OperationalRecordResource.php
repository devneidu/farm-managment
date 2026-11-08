<?php

namespace App\Http\Resources;

use App\Services\Records\OperationCatalogue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class OperationalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'production_cycle_id' => $this->production_cycle_id, 'type' => $this->type,
            /** The readable name: a built-in type's label, or the name of the farm-made type. */
            'type_name' => $this->customType?->name ?? OperationCatalogue::LABELS[$this->type] ?? Str::headline($this->type),
            /** Set on `custom` records and on their reversals. */
            'custom_record_type_id' => $this->custom_record_type_id, 'custom_schema_version' => $this->custom_schema_version,
            /** `custom` records only: the type and the stored values with their field labels (hidden fields included, so history stays readable).
             * @var array{type: array{id: string, name: string, source: string, status: string, version: int}, fields: list<array{key: string, label: string, kind: string, unit: string|null, hidden: bool, value: mixed}>}|null
             */
            'custom' => $this->when($this->type === 'custom' && $this->customType !== null, fn () => $this->customPart()),
            /** @var object */
            'details' => $this->details,
            /** @var object|null */
            'measurement' => $this->measurement,
            'population_delta' => $this->population_delta, 'recorded_at' => $this->recorded_at->toISOString(),
            'notes' => $this->notes, 'reverses_record_id' => $this->reverses_record_id, 'corrects_record_id' => $this->corrects_record_id,
            'linked_record_id' => $this->linked_record_id, 'reversed_by_record_id' => $this->reversal?->id, 'inventory_movement_id' => $this->inventoryMovement?->id, 'created_by' => $this->created_by,
            'created_at' => $this->created_at->toISOString(), 'attachments' => RecordAttachmentResource::collection($this->attachments)];
    }

    /** Values labelled from the type's CURRENT schema (a field's key never changes, only its label). */
    private function customPart(): array
    {
        $type = $this->customType;
        $values = $this->details ?? [];
        $fields = [];
        foreach ($type->fields as $f) {
            if (array_key_exists($f['key'], $values)) {
                $fields[] = ['key' => $f['key'], 'label' => $f['label'], 'kind' => $f['kind'], 'unit' => $f['unit'] ?? null, 'hidden' => $f['hidden'], 'value' => $values[$f['key']]];
            }
        }

        return ['type' => ['id' => $type->id, 'name' => $type->name, 'source' => $type->source, 'status' => $type->status, 'version' => $type->version], 'fields' => $fields];
    }
}
